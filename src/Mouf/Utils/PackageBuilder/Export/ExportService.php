<?php
namespace Mouf\Utils\PackageBuilder\Export;

use Mouf\MoufInstanceDescriptor;
use SplObjectStorage;
use Mouf\MoufManager;
/**
 * This class is in charge of exporting instances from Mouf, as PHP code. 
 * 
 * @author David Négrier <david@mouf-php.com>
 */
class ExportService {
	
	private $instanceList;
	
	private $outOfScopeInstances;
	
	private $usedConfigConstants = array();
	
	public function export(array $instanceNames, MoufManager $moufManager) {
		$this->outOfScopeInstances = new \SplObjectStorage();
		
		$instanceList = $this->getAllInstancesListFromNames($instanceNames, $moufManager);
		
		// First, let's give a name to all those instances (attached in the SplObjectStorage as a value)
		foreach ($instanceList as $instance) {
			$instanceList->attach($instance, $this->getVariableName($instance));
		}
		$this->instanceList = $instanceList;
		
		// Now, let's do it in 3 steps:
		
		// Step 1: generate instances:
		$instanceCode = "";
		
		foreach ($instanceList as $instance) {
			/* @var $instance MoufInstanceDescriptor */
			if (!$instance->isAnonymous()) {
				$className = ($instance->getType() == MoufInstanceDescriptor::TYPE_PHP)?null:$instance->getClassName();
				$instanceCode .= $instanceList[$instance].' = InstallUtils::getOrCreateInstance('.var_export($instance->getName(), true).', '.var_export($className, true).', $moufManager);'."\n";
			} else {
				$instanceCode .= $instanceList[$instance].' = $moufManager->createInstance('.var_export($instance->getClassName(), true).');'."\n";
			}
			// If this is a PHP code instance, let's put the code right now.
			if ($instance->getType() == MoufInstanceDescriptor::TYPE_PHP) {
				$instanceCode .= $instanceList[$instance].'->setCode('.var_export($instance->getCode(), true).');';
			}
		}
		
		$bindCode = "";
		
		// Step 2: bind instances and properties
		foreach ($instanceList as $instance) {
			if (!$this->outOfScopeInstances->contains($instance)) {
				$bindCode .= $this->getBindCode($instance);
			}
		}
		
		$outOfScopeCode = "";
		
		// Now, the $outOfScopeInstances are filled. Let's put them at the top.
		foreach ($this->outOfScopeInstances as $instance) {
			$outOfScopeCode .= $instanceList[$instance].' = $moufManager->getInstanceDescriptor('.var_export($instance->getIdentifierName(), true).');'."\n";
		}
		
		if ($outOfScopeCode) {
			$outOfScopeCode = "// These instances are expected to exist when the installer is run.\n".$outOfScopeCode;
		}
		
		if ($instanceCode) {
			$instanceCode = "// Let's create the instances.\n".$instanceCode;
		}
		
		if ($bindCode) {
			$bindCode = "// Let's bind instances together.\n".$bindCode;
		}
		
		// Back to the top again with the config constants that needs declaring
		$configCode = "";
		if ($this->usedConfigConstants) {
			$configManager = $moufManager->getConfigManager();
			$constants = $configManager->getMergedConstants();
			
			$configCode = '$configManager = $moufManager->getConfigManager();'."\n";
			$configCode .= '$constants = $configManager->getMergedConstants();'."\n";
			foreach ($this->usedConfigConstants as $constant) {
				$definition = $constants[$constant];
				$configCode .= 'if ($configManager->getConstantDefinition('.var_export($constant, true).') === null) {'."\n\t";;
				$configCode .= '$configManager->registerConstant('.var_export($constant, true).', '.var_export($definition['type'], true).', '.var_export($definition['defaultValue'], true).', '.var_export($definition['comment'], true).');'."\n";
				$configCode .= '}'."\n";;
			}
		}
	
		$prepend = '$moufManager = MoufManager::getMoufManager();'."\n";
		$append = "// Let's rewrite the MoufComponents.php file to save the component\n\$moufManager->rewriteMouf();\n";
		
		return $prepend."\n".$configCode."\n".$outOfScopeCode."\n".$instanceCode."\n".$bindCode."\n".$append;
		
	}
	
	public function getAllInstancesListFromNames(array $instanceNames, MoufManager $moufManager) {
		$array = new \SplObjectStorage();
		foreach ($instanceNames as $instanceName) {
			$array->attach($moufManager->getInstanceDescriptor($instanceName));
		}
		$array->addAll($this->getAllAnonymousInstancesList($array));
		
		/*foreach ($array as $instanceDescriptor) {
			echo $instanceDescriptor->getIdentifierName()." - ".$instanceDescriptor->getClassDescriptor()->getName()."\n";
		}*/
		
		return $array;
	}
	
	/**
	 * This function will browse all instances passed in parameter and will return all anonymous instances
	 * bound to those objects.
	 * 
	 * @param SplObjectStorage $instances An object storage of MoufInstanceDescriptor
	 * @return \SplObjectStorage
	 */
	public function getAllAnonymousInstancesList(SplObjectStorage $instances) {
		
		$anonymousInstances = new SplObjectStorage();
		
		foreach ($instances as $instance) {
			$anonymousInstances->addAll($this->getAnonymousAttachedInstances($instance));
		}
		
		return $anonymousInstances;
	}
	
	/**
	 * 
	 * @param MoufInstanceDescriptor $instanceDescriptor
	 * @return \SplObjectStorage
	 */
	public function getAnonymousAttachedInstances(MoufInstanceDescriptor $instanceDescriptor) {
		if ($instanceDescriptor->getType() == MoufInstanceDescriptor::TYPE_PHP) {
			// We do not analyze dependencies inside a PHP instance.
			return new \SplObjectStorage();
		}
		$classDescriptor = $instanceDescriptor->getClassDescriptor();
		$result = new \SplObjectStorage();
		foreach ($classDescriptor->getInjectablePropertiesByConstructor() as $name=>$property) {
			/* @var $property MoufPropertyDescriptor */
			$value = $instanceDescriptor->getConstructorArgumentProperty($name)->getValue();
			$result->addAll($this->findAnonymousInstances($value));
		}
		
		foreach ($classDescriptor->getInjectablePropertiesByPublicProperty() as $name=>$property) {
			/* @var $property MoufPropertyDescriptor */
			$value = $instanceDescriptor->getPublicFieldProperty($name)->getValue();
			$result->addAll($this->findAnonymousInstances($value));
		}
		
		foreach ($classDescriptor->getInjectablePropertiesBySetter() as $name=>$property) {
			/* @var $property MoufPropertyDescriptor */
			$value = $instanceDescriptor->getSetterProperty($name)->getValue();
			$result->addAll($this->findAnonymousInstances($value));
		}
		return $result;
	}
	
	/**
	 * Takes in parameter a value (as returned from the getValue method of a property).
	 * Returns a SplObjectStorage of anonymous instances bound to this object.
	 * 
	 * @param mixed $value
	 * @return \SplObjectStorage
	 */
	private function findAnonymousInstances($value) {
		$array = new \SplObjectStorage();
		if ($value instanceof MoufInstanceDescriptor) {
			if ($value->isAnonymous()) {
				$array->attach($value);
				$array->addAll($this->getAnonymousAttachedInstances($value));
				return $array;
			}
		} elseif (is_array($value)) {
			array_walk_recursive($value, function($item) use ($array) {
				if ($item instanceof MoufInstanceDescriptor && $item->isAnonymous()) {
					$array->attach($item);
					$array->addAll($this->getAnonymousAttachedInstances($item));
				}
			});
		}
		return $array;
	}
	
	/**
	 * A list of names used as variables (as key and value)
	 * @var array<string, string>
	 */
	private $names;
	
	private function getVariableName(MoufInstanceDescriptor $instanceDescriptor) {
		if (!$instanceDescriptor->isAnonymous()) {
			return '$'.preg_replace('/[^a-z0-9]/i', '_', $instanceDescriptor->getIdentifierName());
		} else {
			$className = $instanceDescriptor->getClassDescriptor()->getName();
			$pos = strrpos($className, "\\");
			if ($pos !== false) {
				$className = substr($className, $pos+1);
			}
			$instanceName = '$'."anonymous".$className;
			if (!isset($this->names[$instanceName])) {
				$this->names[$instanceName] = $instanceName;
				return $instanceName;
			}
			$i = 2;
			while (true) {
				if (!isset($this->names[$instanceName.$i])) {
					$this->names[$instanceName.$i] = $instanceName.$i;
					return $instanceName.$i;
				}
				$i++;
			}
		}
	}
	
	/**
	 *
	 * @param MoufInstanceDescriptor $instanceDescriptor
	 * @return string
	 */
	public function getBindCode(MoufInstanceDescriptor $instanceDescriptor) {
		$bindCode = "";
		
		if ($instanceDescriptor->getType() == MoufInstanceDescriptor::TYPE_PHP) {
			// No bind code for PHP instances.
			return $bindCode;
		}
		
		$classDescriptor = $instanceDescriptor->getClassDescriptor();
		$result = new \SplObjectStorage();
		foreach ($classDescriptor->getInjectablePropertiesByConstructor() as $name=>$property) {
			/* @var $property MoufPropertyDescriptor */
			$propertyDescriptor = $instanceDescriptor->getConstructorArgumentProperty($name);
			
			if ($propertyDescriptor->isValueSet($name) && $propertyDescriptor->getValue() !== null) {
				$value = $propertyDescriptor->getValue();
				if (!$instanceDescriptor->isAnonymous()) {
					$bindCode .= 'if (!'.$this->instanceList[$instanceDescriptor].'->getConstructorArgumentProperty('.var_export($name, true).')->isValueSet()) {'."\n\t";
				}
				$bindCode .= $this->instanceList[$instanceDescriptor].'->getConstructorArgumentProperty('.var_export($name, true).')->setValue('.$this->getValueCode($value).');'."\n";
				if ($propertyDescriptor->getOrigin() == 'config') {
					$bindCode .= $this->instanceList[$instanceDescriptor].'->getConstructorArgumentProperty('.var_export($name, true).')->setOrigin("config");'."\n";
					$this->usedConfigConstants[$value] = $value;
				}
				if (!$instanceDescriptor->isAnonymous()) {
					$bindCode .= "}\n";
				}
				
			}
		}
	
		foreach ($classDescriptor->getInjectablePropertiesByPublicProperty() as $name=>$property) {
			/* @var $property MoufPropertyDescriptor */
			$propertyDescriptor = $instanceDescriptor->getPublicFieldProperty($name);
			
			if ($propertyDescriptor->isValueSet($name) && $propertyDescriptor->getValue() !== null) {
				$value = $propertyDescriptor->getValue();
				if (!$instanceDescriptor->isAnonymous()) {
					$bindCode .= 'if (!'.$this->instanceList[$instanceDescriptor].'->getPublicFieldProperty('.var_export($name, true).')->isValueSet()) {'."\n\t";
				}
				$bindCode .= $this->instanceList[$instanceDescriptor].'->getPublicFieldProperty('.var_export($name, true).')->setValue('.$this->getValueCode($value).');'."\n";
				if ($propertyDescriptor->getOrigin() == 'config') {
					$bindCode .= $this->instanceList[$instanceDescriptor].'->getPublicFieldProperty('.var_export($name, true).')->setOrigin("config");'."\n";
					$this->usedConfigConstants[$value] = $value;
				}
				if (!$instanceDescriptor->isAnonymous()) {
					$bindCode .= "}\n";
				}
			}
		}
	
		foreach ($classDescriptor->getInjectablePropertiesBySetter() as $name=>$property) {
			/* @var $property MoufPropertyDescriptor */
			$propertyDescriptor = $instanceDescriptor->getSetterProperty($name);
			
			if ($propertyDescriptor->isValueSet($name) && $propertyDescriptor->getValue() !== null) {
				$value = $propertyDescriptor->getValue();
				if (!$instanceDescriptor->isAnonymous()) {
					$bindCode .= 'if (!'.$this->instanceList[$instanceDescriptor].'->getSetterProperty('.var_export($name, true).')->isValueSet()) {'."\n\t";
				}
				$bindCode .= $this->instanceList[$instanceDescriptor].'->getSetterProperty('.var_export($name, true).')->setValue('.$this->getValueCode($value).');'."\n";
				if ($propertyDescriptor->getOrigin() == 'config') {
					$bindCode .= $this->instanceList[$instanceDescriptor].'->getSetterProperty('.var_export($name, true).')->setOrigin("config");'."\n";
					$this->usedConfigConstants[$value] = $value;
				}
				if (!$instanceDescriptor->isAnonymous()) {
					$bindCode .= "}\n";
				}
			}
		}
		return $bindCode;
	}
	
	public function getValueCode($value) {
		$array = new \SplObjectStorage();
		if ($value instanceof MoufInstanceDescriptor) {
			if ($this->instanceList->contains($value)) {
				return $this->instanceList[$value];
			} else {
				// Let's register the new instance for addition.
				// It has to be a non anonymous instance
				if ($value->isAnonymous()) {
					throw new \Exception("The instance ".$value->getIdentifierName()." of class ".$value->getClassName()." should not be anonymous.");
				}
				$this->outOfScopeInstances->attach($value, $this->getVariableName($value));
				$this->instanceList->attach($value, $this->getVariableName($value));
				return $this->outOfScopeInstances[$value];
			}
		} elseif (is_array($value)) {
			$code = "array(";
			foreach ($value as $key=>$val) {
				$code .= var_export($key, true);
				$code .= " => ";
				$code .= $this->getValueCode($val).", ";
			}
			$code .= ")";
			return $code;
		}
		return var_export($value, true);
	}
}
?>
