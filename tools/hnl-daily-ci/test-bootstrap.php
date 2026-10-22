<?php
$root=getcwd();
$loader=require $root.'/vendor/autoload.php';
foreach (['App\\'=>'app/','Tests\\'=>'tests/'] as $prefix=>$dir) {
 $loader->addPsr4($prefix,$root.'/'.$dir,true);
 foreach ($loader->getClassMap() as $class=>$path) if(str_starts_with($class,$prefix))
  $loader->addClassMap([$class=>$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php']);
}
