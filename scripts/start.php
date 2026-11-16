<?php
/** Local standalone launcher. PHP and its extensions must already be installed. */
$root=dirname(__DIR__);chdir($root);
if(PHP_VERSION_ID<80200){fwrite(STDERR,"PHP 8.2 or newer is required.\n");exit(1);}
$missing=[];foreach(['intl','mbstring','pdo_sqlite','sqlite3','curl','zip','fileinfo'] as $extension)if(!extension_loaded($extension))$missing[]=$extension;
if($missing){fwrite(STDERR,'Enable these PHP extensions: '.implode(', ',$missing)."\n");exit(1);}
if(!is_file($root.'/vendor/autoload.php')||!is_file($root.'/public/assets/cement/app.js')){fwrite(STDERR,"Install dependencies and build assets first, or use the standalone ZIP.\n");exit(1);}
$options=getopt('',['port:']);$port=filter_var($options['port']??8080,FILTER_VALIDATE_INT);
if(!$port||$port<1024||$port>65535){fwrite(STDERR,"Choose a port between 1024 and 65535.\n");exit(1);}
foreach(['cache','logs','session','uploads','attachments'] as $directory)if(!is_dir($root.'/writable/'.$directory))mkdir($root.'/writable/'.$directory,0700,true);
putenv('CEMENT_DRIVER=local');
echo "Cement App standalone: http://127.0.0.1:$port\nCreate your institution and administrator on first opening.\nKeep this terminal open. Press Ctrl+C to stop.\n";
passthru(escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$port.' -t public scripts/router.php',$status);exit($status);
