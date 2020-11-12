<?php


namespace DucCnzj\RpcFacadesGenerator\Replacers;


class CheckMapFieldReplacer implements ReplacerInterface
{
    public function replace(string $path, string $content, array &$data)
    {
        preg_match_all("/GPBUtil::checkMapField\((.*?)\);\n/", $content, $matches);
        foreach (collect($matches[0])->zip($matches[1])->toArray() as $m) {
            if (count($ex = explode(',', $m[1])) != 4) {
                continue;
            }
            $target = trim(rtrim($ex[3], "::class"));
            $code = file_get_contents(__DIR__."/../stubs/checkMapField.stub");

            $newCode = str_replace(['{{class}}'], [$target], $code);
            if ($data[$path] ?? false) {
                $content = $data[$path];
            }
            $data[$path] = str_replace($m[0], $m[0].$newCode, $content);
        }
    }
}