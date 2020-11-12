<?php

namespace DucCnzj\RpcFacadesGenerator\Replacers;

use Illuminate\Support\Arr;

class CheckMessageReplacer implements ReplacerInterface
{
    public function replace(string $path, string $content, array &$data)
    {
        preg_match_all("/GPBUtil::checkMessage\((.*?)\);\n/", $content, $matches);
        foreach (collect($matches[0])->zip($matches[1])->toArray() as $m) {
            $target = trim(Arr::last(explode(',', rtrim($m[1], '::class'))));
            $code = file_get_contents(__DIR__ . '/../stubs/checkMessage.stub');

            $newCode = str_replace(['{{class}}'], [$target], $code);
            if ($data[$path] ?? false) {
                $content = $data[$path];
            }
            $data[$path] = str_replace($m[0], $m[0] . $newCode, $content);
        }
    }
}
