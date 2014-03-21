<?php

namespace HeavyCodeGroup\LinkPub\Consumer;

use Doctrine\Common\Annotations\AnnotationReader;
use HeavyCodeGroup\LinkPub\Consumer\Distribution\MakefileWriter;

class Distribution
{
    protected $classes = array();

    protected $proxies = array();

    protected $fileExtension = '.php';

    protected $annotationClass = array(
        'component'     => 'HeavyCodeGroup\\LinkPub\\Consumer\\Declaration\\Annotation\\Component',
        'basis'         => 'HeavyCodeGroup\\LinkPub\\Consumer\\Declaration\\Annotation\\Basis',
        'consumer_guid' => 'HeavyCodeGroup\\LinkPub\\Consumer\\Declaration\\Annotation\\ConsumerGUID',
    );

    protected $proxyNamespace = 'HeavyCodeGroup\\LinkPub\\Consumer\\Proxy';

    /**
     * @var AnnotationReader
     */
    protected $reader;

    public function __construct()
    {
        $this->reader = new AnnotationReader();
    }

    /**
     * @return array
     */
    public function getClasses()
    {
        return $this->classes;
    }

    /**
     * @return array
     */
    public function getBasisClasses()
    {
        $dist = $this;
        return array_filter($this->classes, function ($class) use ($dist) {
            return $dist->isConsumerBasis($class);
        });
    }

    /**
     * @return string
     */
    public function getConsumerGUID()
    {
        foreach ($this->getBasisClasses() as $class) {
            $refClass = new \ReflectionClass($class);
            $fqcn = '\\' . $refClass->getName();
            foreach ($refClass->getMethods() as $refMethod) {
                $method = $refMethod->getName();
                if ($this->reader->getMethodAnnotation($refMethod, $this->annotationClass['consumer_guid'])) {
                    if ($refMethod->isStatic()) {
                        return $fqcn::$method();
                    } else {
                        if ($refClass->isAbstract()) {
                            $fqcn = '\\' . $this->getProxy($class);
                        }
                        $instance = new $fqcn();
                        return $instance->$method();
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param string $dir
     */
    public function addDirectory($dir)
    {
        $includedFiles = array();

        $iterator = new \RegexIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            ),
            '/^.+' . preg_quote($this->fileExtension) . '$/i',
            \RecursiveRegexIterator::GET_MATCH
        );

        foreach ($iterator as $file) {
            $sourceFile = realpath($file[0]);

            require_once $sourceFile;

            $includedFiles[] = $sourceFile;
        }

        $dist = $this;
        $declared = array_filter(get_declared_classes(), function ($className) use ($includedFiles, $dist) {
            $refClass = new \ReflectionClass($className);
            $sourceFile = $refClass->getFileName();

            return (in_array($sourceFile, $includedFiles) && $dist->isConsumerComponent($refClass) &&
                !$dist->isClassAlreadyAdded($refClass));
        });

        $this->classes = array_merge($this->classes, $declared);
    }

    /**
     * @param string $filename
     * @return int
     */
    public function writeMakefile($filename)
    {
        $mw = new MakefileWriter();

        $mw->newRule('default');

        $mw->newRule('clean');
        $mw->appendAction('clean', 'rm -rf build/*');

        $mw->newRule('build/src');
        $mw->appendAction('build/src', 'mkdir -p build/src');

        $mw->newRule('build/release');
        $mw->appendAction('build/release', 'date +\'%Y-%m-%d %H:%M:%S\' > build/release');

        $mw->newRule('bundle');
        $mw->appendAction('bundle', "tar -cjvf {$this->getConsumerGUID()}.tar.bz2 -C build src release");
        $mw->appendDependency('bundle', 'build/release');

        $mw->appendDependency('default', 'clean');
        $mw->appendDependency('default', 'bundle');

        foreach ($this->getClasses() as $class) {
            $refClass = new \ReflectionClass($class);
            $sourceFile = $refClass->getFileName();

            $rule = 'build/src/' . basename($sourceFile);
            $mw->newRule($rule);
            $mw->appendAction($rule, "cp $sourceFile $rule");
            $mw->appendDependency($rule, 'build/src');
            $mw->appendDependency('bundle', $rule);
        }

        return $mw->writeMakefile($filename);
    }

    /**
     * @param \ReflectionClass|string $class
     * @return bool
     */
    protected function isConsumerComponent($class)
    {
        if (!($class instanceof \ReflectionClass)) {
            $class = new \ReflectionClass($class);
        }

        return ($this->reader->getClassAnnotation($class, $this->annotationClass['component']) &&
            !$class->getNamespaceName());
    }

    /**
     * @param \ReflectionClass|string $class
     * @return bool
     */
    protected function isConsumerBasis($class)
    {
        if (!($class instanceof \ReflectionClass)) {
            $class = new \ReflectionClass($class);
        }

        return ($this->reader->getClassAnnotation($class, $this->annotationClass['basis']) &&
            !$class->getNamespaceName());
    }

    /**
     * @param \ReflectionClass|string $class
     * @return bool
     */
    protected function isClassAlreadyAdded($class)
    {
        if ($class instanceof \ReflectionClass) {
            $class = $class->getName();
        }

        return in_array($class, $this->classes);
    }

    /**
     * @param \ReflectionClass|string $class
     * @return string
     */
    protected function createProxy($class)
    {
        if (!($class instanceof \ReflectionClass)) {
            $class = new \ReflectionClass($class);
        }

        $originalClassName = $class->getName();
        $proxyClassName = 'Proxy' . count($this->proxies) . '_' . str_replace('\\', '_', $originalClassName);
        $overriddenMethods = array_map(function (\ReflectionMethod $refMethod) {
            return 'public function ' . $refMethod->getName() . "()\n{\n}\n";
        }, array_filter(
            $class->getMethods(),
            function (\ReflectionMethod $refMethod) {
                return $refMethod->isAbstract();
            }
        ));
        eval("namespace {$this->proxyNamespace}\n{\nclass $proxyClassName extends \\$originalClassName\n{\n" .
            implode("\n", $overriddenMethods) .
            "}\n}\n");
        $this->proxies[$originalClassName] = $proxyClassName;

        return $this->proxyNamespace . '\\' . $proxyClassName;
    }

    /**
     * @param \ReflectionClass|string $class
     * @return string
     */
    protected function getProxy($class)
    {
        if ($class instanceof \ReflectionClass) {
            $class = $class->getName();
        }

        if (!isset($this->proxies[$class])) {
            return $this->createProxy($class);
        }

        return $this->proxyNamespace . '\\' . $this->proxies[$class];
    }
}
