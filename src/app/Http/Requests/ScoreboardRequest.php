<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ScoreboardRequest extends FormRequest
{
    /** Compared after dropping case and punctuation, so "Ad.min" is refused too. */
    private const RESERVED = ['admin', 'administrator', 'demo', 'moderator', 'mod', 'staff', 'support', 'system', 'owner', 'wowflipledger'];

    protected function prepareForValidation(): void
    {
        $this->merge([
            'scoreboard_opt_in' => $this->boolean('scoreboard_opt_in'),
            'scoreboard_nickname' => trim((string) $this->input('scoreboard_nickname')) ?: null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scoreboard_opt_in' => ['boolean'],
            'scoreboard_nickname' => [
                'nullable',
                'required_if:scoreboard_opt_in,true',
                'string', 'min:3', 'max:24', 'regex:/^[A-Za-z0-9 _.-]+$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (in_array(preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $value)), self::RESERVED, true)) {
                        $fail(__('That nickname is reserved.'));
                    }
                },
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (User::whereRaw('lower(scoreboard_nickname) = ?', [mb_strtolower((string) $value)])->where('id', '!=', $this->user()->id)->exists()) {
                        $fail(__('That nickname is taken.'));
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scoreboard_nickname.required_if' => __('Pick a nickname to join the scoreboard.'),
            'scoreboard_nickname.regex' => __('Use letters, numbers, spaces, dots, dashes and underscores only.'),
        ];
    }
}
