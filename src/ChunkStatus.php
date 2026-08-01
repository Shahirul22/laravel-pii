<?php

namespace Shahirul22\LaravelPiiSanitizer;

enum ChunkStatus: string
{
    case Completed = 'completed';
    case RolledBack = 'rolled_back';
}
