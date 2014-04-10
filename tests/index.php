<?php
use \Ashrey\Gettext\GNU;
use \Ashrey\Gettext\PHP;
require  __DIR__."/../autoload.php";
register_gettext_autoload();

$dirname = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
$gn = new PHP($dirname . "/", "gettext", "es");
$ge = new GNU($dirname . "/", "gettext", "es");
var_dump($gn->gettext("File does not exist"));
var_dump($ge->gettext("File does not exist"));
var_dump($gn->gettext("File does not exist") == $ge->gettext("File does not exist"));
var_dump($gn->ngettext("File is too small", "Files are too small", 1));
var_dump($ge->ngettext("File is too small", "Files are too small", 1));
var_dump($gn->ngettext("File is too small", "Files are too small", 1) == $ge->ngettext("File is too small", "Files are too small", 1));
var_dump($gn->ngettext("File is too small", "Files are too small", 2));
var_dump($ge->ngettext("File is too small", "Files are too small", 2));
var_dump($gn->ngettext("File is too small", "Files are too small", 2) == $ge->ngettext("File is too small", "Files are too small", 2));

