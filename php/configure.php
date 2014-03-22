<?php

require __DIR__ . '/vendor/autoload.php';

use Doctrine\Common\Annotations\AnnotationRegistry;
use HeavyCodeGroup\LinkPub\Consumer\Distribution;

// Register annotations autoloading
AnnotationRegistry::registerLoader(function ($className) {
    $class = explode('\\', $className);
    if ((count($class) != 2) || ($class[0] != 'LinkPubConsumer')) {
        return false;
    }

    $fullClassName = 'HeavyCodeGroup\\LinkPub\\Consumer\\Declaration\\Annotation\\' . $class[1];
    return (class_exists($fullClassName) && class_alias($fullClassName, $className));
});

$dist = new Distribution();
echo "Adding directory: src/\n";
$dist->addDirectory(__DIR__ . '/src');

echo "Loaded classes:\n";
foreach ($dist->getClasses() as $class) {
    echo " -> $class\n";
}

if (count($dist->getBasisClasses()) != 1) {
    die("You must have exactly ONE class with '@LinkPubConsumer\\Basis' annotation\n");
}

echo "Basis classes:\n";
foreach ($dist->getBasisClasses() as $class) {
    echo " -> $class\n";
}

$guid = $dist->getConsumerGUID();
if (!preg_match('/^[a-f0-9]{8}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{12}$/i', $guid)) {
    die("Failed to extract consumer version GUID.\n" .
        "Make sure you have '@LinkPubConsumer\\ConsumerGUID' on public method in class with '@LinkPubConsumer\\Basis'" .
        " and it returns correct GUID.\n"
    );
}
echo "Consumer GUID: $guid\n";

if ($dist->writeYaml(__DIR__ . '/consumer.yml')) {
    echo "Written YAML.\n";
}

if ($dist->writeMakefile(__DIR__ . '/Makefile')) {
    echo "Written Makefile.\n";
}
