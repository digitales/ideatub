<?php

namespace App\Enums;

enum ThoughtGraphMode: string
{
    case Local = 'local';
    case Project = 'project';
    case Tag = 'tag';
    case Semantic = 'semantic';
    case Vault = 'vault';
}
