<?php
use Mouf\MoufManager;
use Mouf\MoufUtils;

MoufUtils::registerMainMenu('utilsMainMenu', 'Utils', null, 'mainMenu', 200);
MoufUtils::registerMenuItem('utilsPackageBuilderInterfaceMenu', 'Package builder', null, 'utilsMainMenu', 60);
MoufUtils::registerMenuItem('utilsPackageBuilderExportInstacesMenuItem', 'Export instances', 'export/', 'utilsPackageBuilderInterfaceMenu', 10);

$moufManager = MoufManager::getMoufManager();

// Controller declaration
$moufManager->declareComponent('export', 'Mouf\\Utils\\PackageBuilder\\Export\\ExportController', true);
$moufManager->bindComponents('export', 'template', 'moufTemplate');
$moufManager->bindComponents('export', 'content', 'block.content');

unset($moufManager);
?>