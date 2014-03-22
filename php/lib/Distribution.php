<?php

namespace HeavyCodeGroup\LinkPub\Consumer;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\AnnotationReader;
use HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation\ConsumerBasisInterface;
use HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation\ConsumerComponentInterface;
use HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation\ConsumerIdentificationInterface;
use HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation\MethodOverrideInterface;
use HeavyCodeGroup\LinkPub\Consumer\Distribution\MakefileWriter;
use Symfony\Component\Yaml;

class Distribution
{
    protected $classes = array();

    protected $proxies = array();

    protected $fileExtension = '.php';

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
     * @param array $classes
     * @return array
     */
    public function getSourceFiles($classes)
    {
        return array_unique(array_map(function ($class) {
            $refClass = new \ReflectionClass($class);
            return $refClass->getFileName();
        }, $classes));
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
                if (count(array_filter($this->reader->getMethodAnnotations($refMethod), function (Annotation $annotation) {
                    return ($annotation instanceof ConsumerIdentificationInterface);
                })) > 0) {
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

        $mw->newRule('build/consumer.yml');
        $mw->appendAction('build/consumer.yml', 'cp consumer.yml build/consumer.yml');

        $mw->newRule('build/consumer.php');
        $mw->appendAction('build/consumer.php', "echo '<?php' > build/consumer.php");

        $mw->newRule('build/src');
        $mw->appendAction('build/src', 'mkdir -p build/src');

        $mw->newRule('bundle');
        $mw->appendAction('bundle', "tar -cjvf {$this->getConsumerGUID()}.tar.bz2 -C build consumer.php consumer.yml");
        $mw->appendDependency('bundle', 'build/consumer.yml');
        $mw->appendDependency('bundle', 'build/consumer.php');

        $mw->appendDependency('default', 'clean');
        $mw->appendDependency('default', 'bundle');

        foreach ($this->getSourceFiles($this->getClasses()) as $sourceFile) {
            $rule = 'build/src/' . basename($sourceFile);
            $mw->newRule($rule);
            $mw->appendAction($rule, "tail -n +2 $sourceFile > $rule");
            $mw->appendDependency($rule, 'build/src');
            $mw->appendDependency('build/consumer.php', $rule);
            $mw->appendAction('build/consumer.php', "cat $rule >> build/consumer.php");
        }

        return $mw->writeMakefile($filename);
    }

    public function writeYaml($filename)
    {
        $basisClasses = $this->getBasisClasses();
        $basisClassRef = new \ReflectionClass($basisClasses[0]);
        $method_overrides = array();
        foreach ($basisClassRef->getMethods() as $methodRef) {
            $annotations = $this->reader->getMethodAnnotations($methodRef);
            $override = null;
            foreach ($annotations as $annotation) {
                if ($annotation instanceof MethodOverrideInterface) {
                    if ($override !== null) {
                        throw new \Exception(sprintf(
                            'Too many override annotations on method %s::%s',
                            $basisClassRef->getName(),
                            $methodRef->getName()
                        ));
                    }
                    $override = $annotation->getOverrideContext();
                }
            }
            if ($methodRef->isAbstract() && ($override === null)) {
                throw new \Exception(sprintf(
                    'Abstract method %s::%s must be overridden',
                    $basisClassRef->getName(),
                    $methodRef->getName()
                ));
            }
            if ($override !== null) {
                $method_overrides[$methodRef->getName()] = $override;
            }
        }

        $dumper = new Yaml\Dumper();
        $now = new \DateTime();

        $data = array(
            'implementation' => 'php',
            'consumer_guid' => $this->getConsumerGUID(),
            'release_date' => $now->format('Y-m-d H:i:s'),
            'file_sources' => array('consumer.php'),
            'class_base' => implode(array_slice($this->getBasisClasses(), 0, 1)),
            'method_overrides' => $method_overrides,
        );

        return file_put_contents($filename, $dumper->dump($data, 3));
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

        return ((count(array_filter($this->reader->getClassAnnotations($class), function (Annotation $annotation) {
                    return ($annotation instanceof ConsumerComponentInterface);
                })) > 0) && !$class->getNamespaceName());
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

        return ((count(array_filter($this->reader->getClassAnnotations($class), function (Annotation $annotation) {
                    return ($annotation instanceof ConsumerBasisInterface);
                })) > 0) && !$class->getNamespaceName());
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
