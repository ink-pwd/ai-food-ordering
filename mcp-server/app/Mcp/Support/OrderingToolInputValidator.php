<?php

namespace App\Mcp\Support;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

final class OrderingToolInputValidator
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function validate(Request $request, array $rules): array
    {
        $attributes = [];

        foreach (array_keys($rules) as $argument) {
            $attributes[$argument] = $argument;
        }

        return $request->validate($rules, [
            'required' => 'Аргумент :attribute обязателен.',
            'string' => 'Аргумент :attribute должен быть строкой.',
            'integer' => 'Аргумент :attribute должен быть целым числом.',
            'min' => 'Аргумент :attribute не соответствует минимальному ограничению :min.',
            'max' => 'Аргумент :attribute превышает максимально допустимое ограничение :max.',
        ], $attributes);
    }

    /**
     * @param  list<string>  $allowedArguments
     */
    public function rejectUnexpected(Request $request, array $allowedArguments): void
    {
        $unexpectedArguments = array_values(array_diff(
            array_keys($request->all()),
            $allowedArguments,
        ));

        if ($unexpectedArguments === []) {
            return;
        }

        $messages = [];

        foreach ($unexpectedArguments as $argument) {
            $messages[$argument] = "Аргумент {$argument} не поддерживается этим инструментом.";
        }

        throw ValidationException::withMessages($messages);
    }
}
