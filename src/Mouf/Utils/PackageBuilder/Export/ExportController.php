<?php
namespace Mouf\Utils\PackageBuilder\Export;

use Mouf\MoufManager;
use Mouf\Mvc\Splash\Controllers\Controller;
use Mouf\Html\Template\TemplateInterface;
use Mouf\Html\HtmlElement\HtmlBlock;

/**
 * This class is displaying the export UI to generate PHP code to generate instances
 * based on existing instances in your workspace. 
 * 
 * @author David Négrier <david@mouf-php.com>
 */
class ExportController extends Controller {
	
	/**
	 *
	 * @var TemplateInterface
	 */
	public $template;
	
	/**
	 *
	 * @var HtmlBlock
	 */
	public $content;
	
	/**
	 * Admin page used to export instances.
	 *
	 * @Action
	 */
	public function index($selfedit="false") {
		$this->content->addFile(dirname(__FILE__)."/../../../../views/exportForm.php", $this);
		$this->template->toHtml();
	}
	
	/**
	 * This action generates the objects from the SQL query and creates a new SELECT instance.
	 *
	 * @Action
	 * @param string $instances
	 * @param string $selfedit
	 */
	public function export($instances, $selfedit="false") {
		if ($selfedit == "true") {
			$moufManager = MoufManager::getMoufManager();
		} else {
			$moufManager = MoufManager::getMoufManagerHiddenInstance();
		}
		
		$instancesList = explode("\n", $instances);
		$cleaninstancesList = array();
		foreach ($instancesList as $instance) {
			$instance = trim($instance, "\r ");
			if (!empty($instance)) {
				$cleaninstancesList[] = $instance;
			}
		}

		$exportService = new ExportService();
		$exportService->export($cleaninstancesList, $moufManager);
	}
	
}
?>
