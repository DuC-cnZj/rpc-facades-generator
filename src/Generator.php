<?php

namespace DucCnzj\RpcFacadesGenerator;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Contracts\Filesystem\Filesystem;

class Generator
{
    private string $rootPath;

    private array $data = [];

    public Filesystem $fileManager;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
        $this->validatePath($rootPath);
        $this->fileManager = (new FilesystemManager(null))->createLocalDriver(['root' => $this->rootPath]);
    }

    public function getGRPCData()
    {
        $this->data = collect($this->fileManager->allFiles())
            ->filter(fn ($name) => Str::contains($name, 'Client'))
            ->reject(fn ($name) => Str::contains($name, ['Facades', 'Services']))
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
                foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (! $method->isConstructor() && $class->getName() == $method->class) {
                        $methods[] = [
                            'method'                 => $method->getName(),
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
 * @method static mixed {{method}}(array \$data)\n
DOC;
        $doc = '';
        foreach ($methods as $method) {
            $doc .= str_replace('{{method}}', $method['method'], $m);
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
            $m = <<<Methods
        public function {{method}}(\$data = [])
        {
            \$request = new {{argument}}(\$data);
            [\$data, \$response] = \$this->client->{{method}}(\$request)->wait();
            if (\$response->code == \Grpc\CALL_OK) {
                return \$data;
            }
    
            throw new \Exception(\$response->details, \$response->code);
        }\n\n
    Methods;

            $methods = '';
            foreach ($data['methods'] as $method) {
                $methods .= str_replace(['{{method}}', '{{argument}}'], [$method['method'], $method['argumentShortClassName']], $m);
            }

            $useClassList = collect($data['methods'])->pluck('argument')->unique()->merge($data['class'])->map(fn ($class) => "use $class;\n")->implode('');

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
        $useClassList = collect($this->data)->pluck('class')->map(fn ($class) => "use $class;\n")->implode('');
        $registerDef = <<<REGISTER
        \$this->app->when({{rpcClass}}::class)
            ->needs('\$hostname')
            ->give(env("{{rpcHost}}"));
        \$this->app->when({{rpcClass}}::class)
            ->needs('\$opts')
            ->give([
                'credentials' => \Grpc\ChannelCredentials::createInsecure(),
            ]);
REGISTER;

        $registers = collect($this->data)->map(function ($item) use ($registerDef) {
            $rpcClass = $item['shortClassName'];
            $rpcHost = Str::upper(Str::of($item['class'])->explode('\\')->first()). "_HOST";

            return str_replace(['{{rpcClass}}', '{{rpcHost}}'], [$rpcClass, $rpcHost], $registerDef);
        })->implode("\n");

        $file = str_replace(['{{namespace}}', '{{useClassList}}', '{{registers}}'], [$namespace, $useClassList, $registers], file_get_contents(__DIR__ . '/stubs/provider.stub'));

        $path = $this->rootPath;
        file_put_contents($path . '/ServiceProvider.php', $file);
    }

    private function validatePath(string $rootPath)
    {
        // todo 通过 composer.json 获取 rootPath
        $composerFile = rtrim($rootPath, DIRECTORY_SEPARATOR). DIRECTORY_SEPARATOR . 'composer.json';
        if (!file_exists($composerFile)) {
            throw new \Exception('path 下必须有 composer.json');
        }
        preg_match("/(src.*)[^\"]/", file_get_contents($composerFile), $match);
        $this->rootPath = $rootPath . DIRECTORY_SEPARATOR . $match[1];
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
    }

    public function toArray()
    {
        return collect($this->data)->toArray();
    }
}