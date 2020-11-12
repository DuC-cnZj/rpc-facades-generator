<?php

namespace DucCnzj\RpcFacadesGenerator\Replacers;

interface ReplacerInterface
{
    public function replace(string $path, string $content, array &$data);
}
