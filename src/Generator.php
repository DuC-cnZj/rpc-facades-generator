<?php

namespace DucCnzj\RpcFacadesGenerator;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use DucCnzj\RpcFacadesGenerator\Replacers\CheckMessageReplacer;
use DucCnzj\RpcFacadesGenerator\Replacers\CheckMapFieldReplacer;
use DucCnzj\RpcFacadesGenerator\Replacers\CheckRepeatedFieldReplacer;

class Generator
{
    /**
     * @var string
     */
    private $rootPath;

    private $nsPrefix;

    /**
     * @var array
     */
    private $data = [];

    /**
     * @var Collection
     */
    private $messageFiles;

    private $replacers = [
        CheckMapFieldReplacer::class,
        CheckMessageReplacer::class,
        CheckRepeatedFieldReplacer::class,
    ];

    /**
     * @var Filesystem
     */
    public $fileManager;

    public $replaceGRPCFileMap = [];

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
        $this->validatePath($rootPath);
        $this->fileManager = (new FilesystemManager(null))->createLocalDriver(['root' => $this->rootPath]);
    }

    public function getGRPCData()
    {
        $this->fileManager->delete($this->fileManager->allFiles('Facades', 'Services'));
        $this->data = collect($this->fileManager->allFiles())
            ->filter(function ($name) {
                return Str::contains($name, 'Client');
            })
            ->reject(function ($name) {
                return Str::contains($name, ['Facades', 'Services']);
            })
            ->values()
            ->map(function ($name) {
                $content = file_get_contents($this->rootPath . '/' . $name);
                preg_match("/namespace\s+(.*);/", $content, $matchNs);
                preg_match("/class\s+(.*)\s+extends/", $content, $matchClass);
                $namespace = $matchNs[1];
                $class = $matchClass[1];
                $fullClassName = "$namespace\\$class";
                $class = new \ReflectionClass($fullClassName);
                $methods = [];
                $fileArray = explode("\n", file_get_contents($class->getFileName()));

                foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (! $method->isConstructor() && $class->getName() == $method->class) {
                        $data = collect($fileArray)->slice($method->getStartLine(), $method->getEndLine() - $method->getStartLine())->implode('');
                        preg_match("/\['(.*?)', 'decode'\]/", $data, $match);
                        $params = '';
                        $paramFields = [];
                        $inline = false;
                        $input = '$data';
                        $paramFieldsStr = '$data = []';

                        foreach ($method->getParameters()[0]->getClass()->getMethods() as $m) {
                            if (Str::startsWith($m->getName(), 'set')) {
                                preg_match('/@param\s+(.*?)\s+/', $m->getDocComment(), $matches);
                                if (Str::contains($method->getDocComment(), 'php:inline')) {
                                    $inline = true;
                                }
                                $type = $matches[1];
                                if (Str::contains($type, '\Google\Protobuf\Internal\RepeatedField')) {
                                    $type = 'array|' . $type;
                                }
                                $name = Str::camel(Str::after($m->getName(), 'set'));
                                $params .= "     *     @type {$type} \${$name}\n";
                                $paramFields[$name] = $type;

                                $paramFieldsArr = [];
                                $inputArr = [];
                                if ($inline) {
                                    foreach ($paramFields as $field => $type) {
                                        switch ($type) {
                                            case Str::contains($type, 'array'):
                                                $ft = '[]';
                                                break;
                                            case Str::contains($type, ['int', 'integer', 'string']):
                                                $ft = "''";
                                                break;
                                            case Str::contains($type, ['float', 'double']):
                                                $ft = '0.0';
                                                break;
                                            case Str::contains($type, ['boolean', 'bool']):
                                                $ft = 'false';
                                                break;
                                        }
                                        $paramFieldsArr[] = "\$$field = $ft";
                                        $inputArr[] = "\"$field\" => \$$field";
                                    }
                                }
                                $input = $inline ? '[' . implode(', ', $inputArr) . ']' : $input;
                                $paramFieldsStr = $inline ? implode(', ', $paramFieldsArr) : '$data = []';
                            }
                        }

                        $methods[] = [
                            'input'                  => $input,
                            'paramFieldsStr'         => $paramFieldsStr,
                            'inline'                 => $inline,
                            'paramFields'            => $paramFields,
                            'params'                 => rtrim($params),
                            'method'                 => $method->getName(),
                            'return'                 => $match[1],
                            'argument'               => $method->getParameters()[0]->getClass()->getName(),
                            'argumentShortClassName' => $method->getParameters()[0]->getClass()->getShortName(),
                        ];
                    }
                }
                $arr = explode('\\', $class->getName());
                $targetFile = Arr::last($arr) . '.php';
                array_pop($arr);
                $targetDir = implode(DIRECTORY_SEPARATOR, $arr);

                return [
                    'class'          => $class->getName(),
                    'shortClassName' => $class->getShortName(),
                    'targetFile'     => $targetFile,
                    'targetDir'      => $targetDir,
                    'methods'        => $methods,
                ];
            })->values()->toArray();

        $this->messageFiles = collect($this->fileManager->allFiles())
            ->reject(function ($name) {
                return Str::contains($name, ['Facades', 'Services']);
            })
            ->mapWithKeys(function ($name) {
                $content = file_get_contents($path = $this->rootPath . '/' . $name);
                if (! Str::contains($content, 'extends \Google\Protobuf\Internal\Message')) {
                    return [];
                }

                return [$path => $content];
            })->filter();

        return $this;
    }

    public function replaceFacadeStub($class, $svcClass, $methods, $facadeNamespace)
    {
        $m = file_get_contents(__DIR__ . '/stubs/facade_method_doc.stub');
        $doc = '';
        foreach ($methods as $method) {
            $doc .= str_replace(['{{method}}', '{{return}}', '{{paramFieldStr}}'], [$method['method'], $method['return'], $method['paramFieldsStr']], $m);
        }

        return str_replace(['{{namespace}}', '{{class}}', '{{svcClass}}', '{{methods}}'], [$facadeNamespace, $class, $svcClass, rtrim($doc)], file_get_contents(__DIR__ . '/stubs/facade.stub'));
    }

    /**
     * @author duc <1025434218@qq.com>
     */
    public function generateFacade(): void
    {
        foreach ($this->data as $data) {
            $class = ltrim(ltrim($data['class'], $this->nsPrefix), '\\');
            $tmp = explode('\\', $class);
            array_pop($tmp);
            $topNs = array_shift($tmp);
            $facadeNamespace = trim($this->nsPrefix . '\\' . implode('\\', array_merge([$topNs, 'Facades'], $tmp)), '\\');
            $svcNamespace = trim($this->nsPrefix . '\\' . implode('\\', array_merge([$topNs, 'Services'], $tmp)), '\\');
            $svcClass = '\\' . $svcNamespace . '\\' . $data['shortClassName'] . 'Service';
            $file = $this->replaceFacadeStub($data['shortClassName'], $svcClass, $data['methods'], $facadeNamespace);
            $a = explode('\\', $class);
            array_pop($a);
            array_shift($a);
            if (! file_exists($path = $this->getFacadeDir(implode('/', $a)))) {
                mkdir($path, 0777, true);
            }
            file_put_contents($path . '/' . $data['targetFile'], $file);
        }
    }

    /**
     *
     * @author duc <1025434218@qq.com>
     */
    public function generateSvc(): void
    {
        foreach ($this->data as $data) {
            $class = ltrim(ltrim($data['class'], $this->nsPrefix), '\\');

            $topNs = explode('\\', $class)[0];
            $m = file_get_contents(__DIR__ . '/stubs/svc_method.stub');
            $methods = '';
            foreach ($data['methods'] as $method) {
                $params = str_replace('    @type', '@param', $method['params']);
                if (! $method['inline']) {
                    $params = str_replace(['{{params}}', '{{argument}}'], [$params, $method['argumentShortClassName']], file_get_contents(__DIR__ . '/stubs/sub_params.stub'));
                }
                $methods .= str_replace(['{{input}}', '{{paramFields}}', '{{method}}', '{{argument}}', '{{return}}', '{{params}}', '{{svc}}'], [$method['input'], $method['paramFieldsStr'], $method['method'], $method['argumentShortClassName'], $method['return'], $params, $topNs], $m);
            }
            $useClassList = collect($data['methods'])->pluck('argument')->unique()->merge($data['class'])->map(function ($class) {
                return "use $class;\n";
            })->implode('');

            $tmp = explode('\\', $class);
            array_pop($tmp);
            $topNs = array_shift($tmp);
            $svcNamespace = trim($this->nsPrefix . '\\' . implode('\\', array_merge([$topNs, 'Services'], $tmp)), '\\');
            $file = str_replace(['{{namespace}}', '{{useClassList}}', '{{class}}', '{{methods}}'], [$svcNamespace, $useClassList, $data['shortClassName'], rtrim($methods)], file_get_contents(__DIR__ . '/stubs/services.stub'));
            $a = explode('\\', $class);
            array_pop($a);
            array_shift($a);
            if (! file_exists($path = $this->getSvcDir(implode('/', $a)))) {
                mkdir($path, 0777, true);
            }

            $t = explode('.', $data['targetFile']);
            $tf = $t[0] . 'Service.' . $t[1];
            file_put_contents($path . '/' . $tf, $file);
        }
    }

    public function generateProvider()
    {
        $class = ltrim(ltrim(collect($this->data)->pluck('class')->first(), $this->nsPrefix), '\\');

        $namespace = trim($this->nsPrefix . '\\' . Str::of($class)->explode('\\')->first(), '\\');
        $useClassList = collect($this->data)->pluck('class')->map(function ($class) {
            return "use $class;\n";
        })->implode('');
        $registerDef = file_get_contents(__DIR__ . '/stubs/register.stub');

        $registers = collect($this->data)->map(function ($item) use ($class, $registerDef) {
            $rpcClass = $item['shortClassName'];
            $rpcHost = Str::upper(Str::of($class)->explode('\\')->first()) . '_HOST';

            return str_replace(['{{rpcClass}}', '{{rpcHost}}'], [$rpcClass, $rpcHost], $registerDef);
        })->implode("\n");

        $file = str_replace(['{{namespace}}', '{{useClassList}}', '{{registers}}'], [$namespace, $useClassList, $registers], file_get_contents(__DIR__ . '/stubs/provider.stub'));

        $path = $this->rootPath;
        file_put_contents($path . '/ServiceProvider.php', $file);
    }

    private function validatePath(string $rootPath)
    {
        $composerFile = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (! file_exists($composerFile)) {
            throw new \Exception('path 下必须有 composer.json');
        }
        preg_match('/(src.*)[^"]/', $content = file_get_contents($composerFile), $match);
        $ns = array_keys(json_decode($content, true)['autoload']['psr-4'])[0];
        $nsArr = array_filter(explode('\\', $ns));
        array_pop($nsArr);
        $this->nsPrefix = implode('\\', $nsArr);
        $this->rootPath = $rootPath . DIRECTORY_SEPARATOR . rtrim($match[1], '\"');
        dump('root path: ' . $this->rootPath);
    }

    /**
     * @param string $dir
     * @return string
     *
     * @author duc <1025434218@qq.com>
     */
    protected function getFacadeDir(string $dir): string
    {
        return $this->rootPath . '/Facades/' . $dir;
    }

    /**
     * @param string $dir
     * @return string
     *
     * @author duc <1025434218@qq.com>
     */
    protected function getSvcDir(string $dir): string
    {
        return $this->rootPath . '/Services/' . $dir;
    }

    public function writeFile()
    {
        $this->generateFacade();
        $this->generateSvc();
        $this->generateProvider();
        $this->changeRpcFiles();
        $this->replaceGRPCFile();
    }

    public function toArray()
    {
        return collect($this->data)->toArray();
    }

    public function changeRpcFiles()
    {
        $this->messageFiles->each(function ($content, $path) {
            foreach ($this->replacers as $replacer) {
                (new $replacer)->replace($path, $content, $this->replaceGRPCFileMap);
            }
        });
    }

    public function replaceGRPCFile()
    {
        foreach ($this->replaceGRPCFileMap as $file => $content) {
            file_put_contents($file, $content);
        }
    }
}
