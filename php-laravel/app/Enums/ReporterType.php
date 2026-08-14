<?php

declare(strict_types=1);

namespace App\Enums;

enum ReporterType: string
{
    case Public = 'public';
    case Police = 'police';
    case School = 'school';
    case NGO    = 'ngo';
}