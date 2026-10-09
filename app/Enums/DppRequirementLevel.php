<?php

namespace App\Enums;

enum DppRequirementLevel: string
{
    case Required = 'required';
    case Conditional = 'conditional';
    case Recommended = 'recommended';
}
