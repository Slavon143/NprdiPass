<?php

namespace App\Services\Passports\Readiness;

use App\Contracts\Passports\PassportReadinessRule;

class PassportReadinessRuleRegistry
{
    /**
     * @return PassportReadinessRule[]
     */
    public function all(): array
    {
        $ruleClasses = config('passport_readiness.rules', []);

        $rules = [];

        foreach ($ruleClasses as $class) {
            if (is_subclass_of($class, PassportReadinessRule::class, true)) {
                $rules[] = app($class);
            }
        }

        usort($rules, function (PassportReadinessRule $a, PassportReadinessRule $b): int {
            $group = $a->group()->value <=> $b->group()->value;

            return $group !== 0 ? $group : $a->code() <=> $b->code();
        });

        return $rules;
    }
}
