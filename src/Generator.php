<?php

namespace DucCnzj\RpcFacadesGenerator;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Contracts\Filesystem\Filesystem;

class Generator
{
    /**
     * @var string
     */
    private $rootPath;

    /**
     * @var array
     */
    private $data = [];

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
            ->filter(function ($name) {return Str::contains($name, 'Client');})
            ->reject(function ($name) {return Str::contains($name, ['Facades', 'Services']);})
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
                        foreach ($method->getParameters()[0]->getClass()->getMethods() as $m) {
                            if (Str::startsWith($m->getName(), 'set')) {
                                preg_match('/@param\s+(.*?)\s+/', $m->getDocComment(), $matches);
                                $type = $matches[1];
                                if (Str::contains($type, '\Google\Protobuf\Internal\RepeatedField')) {
                                    $type = "array|".$type;
                                }
                                $name = Str::lower(Str::after($m->getName(), 'set'));
                                $params .= "     *     @type {$type} \${$name}\n";
                            }
                        }

                        $this->addArrayAbilityForMethod($method->getParameters()[0]->getClass());

                        $methods[] = [
                            'params'                 => trim($params),
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
                    'class'           => $class->getName(),
                    'shortClassName'  => $class->getShortName(),
                    'targetFile'      => $targetFile,
                    'targetDir'       => $targetDir,
                    'methods'         => $methods,
                ];
            })->values()->toArray();

        return $this;
    }

    public function replaceFacadeStub($class, $svcClass, $methods, $facadeNamespace)
    {
        $m = <<<DOC
 * @method static {{return}}|array {{method}}(\$data = [], bool \$asArray = true)\n
DOC;
        $doc = '';
        foreach ($methods as $method) {
            $doc .= str_replace(['{{method}}', '{{return}}'], [$method['method'], $method['return']], $m);
        }

        return str_replace(['{{namespace}}', '{{class}}', '{{svcClass}}', '{{methods}}'], [$facadeNamespace, $class, $svcClass, rtrim($doc)], file_get_contents(__DIR__ . '/stubs/facade.stub'));
    }

    /**
     * @author duc <1025434218@qq.com>
     */
    public function generateFacade(): void
    {
        foreach ($this->data as $data) {
            $tmp = explode('\\', $data['class']);
            array_pop($tmp);
            $topNs = array_shift($tmp);
            $facadeNamespace = implode('\\', array_merge([$topNs, 'Facades'], $tmp));
            $svcNamespace = implode('\\', array_merge([$topNs, 'Services'], $tmp));
            $svcClass = '\\' . $svcNamespace . '\\' . $data['shortClassName'] . 'Service';
            $file = $this->replaceFacadeStub($data['shortClassName'], $svcClass, $data['methods'], $facadeNamespace);
            $a = explode('\\', $data['class']);
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
            $topNs = explode('\\', $data['class'])[0];
            $m = <<<Methods
    /**
     * @param array|{{argument}} \$data {
     *     Optional. Data for populating the Message object.
     *
{{params}}
     * }
     * @param bool \$asArray
     * @return {{return}}|array
     */
    public function {{method}}(\$data = [], \$asArray = true)
    {
        \$request = \$data;
        if (is_array(\$data)) {
            \$request = new {{argument}}();
            foreach(\$data as \$key => \$value) {
                \$method = 'set' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', \$key)));
                if (method_exists(\$request, \$method)) {
                    \$request->\$method(\$value);
                }
            }
        }

        [\$data, \$response] = \$this->client->{{method}}(\$request)->wait();
        if (\$response->code == \Grpc\CALL_OK) {
            if (\$asArray) {
                return json_decode(\$data->serializeToJsonString(), true);
            }

            return \$data;
        }

        throw new \Exception("{{svc}} rpc client error: " . \$response->details, \$response->code);
    }\n\n
Methods;

            $methods = '';
            foreach ($data['methods'] as $method) {
                $methods .= str_replace(['{{method}}', '{{argument}}', '{{return}}', '{{params}}', '{{svc}}'], [$method['method'], $method['argumentShortClassName'], $method['return'], $method['params'], $topNs], $m);
            }

            $useClassList = collect($data['methods'])->pluck('argument')->unique()->merge($data['class'])->map(function ($class) {return "use $class;\n";})->implode('');

            $tmp = explode('\\', $data['class']);
            array_pop($tmp);
            $topNs = array_shift($tmp);
            $svcNamespace = implode('\\', array_merge([$topNs, 'Services'], $tmp));
            $file = str_replace(['{{namespace}}', '{{useClassList}}', '{{class}}', '{{methods}}'], [$svcNamespace, $useClassList, $data['shortClassName'], rtrim($methods)], file_get_contents(__DIR__ . '/stubs/services.stub'));
            $a = explode('\\', $data['class']);
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
        $namespace = Str::of(collect($this->data)->pluck('class')->first())->explode('\\')->first();
        $useClassList = collect($this->data)->pluck('class')->map(function ($class) {return "use $class;\n";})->implode('');
        $registerDef = <<<REGISTER
        \$this->app->singleton({{rpcClass}}::class);
        \$this->app->when({{rpcClass}}::class)
            ->needs('\$hostname')
            ->give(env("{{rpcHost}}", ""));
        \$this->app->when({{rpcClass}}::class)
            ->needs('\$opts')
            ->give([
                'credentials' => \Grpc\ChannelCredentials::createInsecure(),
            ]);
REGISTER;

        $registers = collect($this->data)->map(function ($item) use ($registerDef) {
            $rpcClass = $item['shortClassName'];
            $rpcHost = Str::upper(Str::of($item['class'])->explode('\\')->first()) . '_HOST';

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
        preg_match('/(src.*)[^"]/', file_get_contents($composerFile), $match);
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
        $this->replaceGRPCFile();
    }

    public function toArray()
    {
        return collect($this->data)->toArray();
    }

    public function addArrayAbilityForMethod(\ReflectionClass $class)
    {
        $data = $this->replaceGRPCFileMap[$class->getFileName()] ?? file_get_contents($class->getFileName());
        preg_match_all("/GPBUtil::checkRepeatedField\((.*?)\);\n/", $data, $m);
        if (count($m) < 2) {
            return;
        }
        foreach (collect($m[0])->zip($m[1])->toArray() as $ms) {
            $this->deal($ms, $class, $data);
        }
    }

    public function deal($m, $class, $data)
    {
        $target = trim(Arr::last(explode(',', rtrim($m[1], "::class"))));
        $code = <<<CODE
        \$tmp = [];
        if (is_array(\$arr)) {
            foreach(\$arr as \$item) {
                \$tmp[] = \$request = new {{class}}();
                foreach (\$item as \$key => \$value) {
                    \$method = 'set' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', \$key)));
                    if (method_exists(\$request, \$method)) {
                        \$request->\$method(\$value);
                    }
                }
            }
            \$arr = \$tmp;
        }\n
CODE;

        $newCode = str_replace(['{{class}}'], [$target], $code);
        if ($this->replaceGRPCFileMap[$class->getFileName()] ?? false) {
            $data = $this->replaceGRPCFileMap[$class->getFileName()];
        }
        $this->replaceGRPCFileMap[$class->getFileName()] = str_replace($m[0], $m[0].$newCode, $data);

        $subClass = new \ReflectionClass($target);
        $this->addArrayAbilityForMethod($subClass);
    }

    public function replaceGRPCFile()
    {
        foreach ($this->replaceGRPCFileMap as $file => $content) {
            file_put_contents($file, $content);
        }
    }
}
