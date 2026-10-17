<?php

test('a validated language preference persists through login pages', function () {
    $this->from('http://localhost/login?lang=en')->post(route('ui-language.update'), ['locale' => 'de'])
        ->assertRedirect('http://localhost/login')->assertSessionHas('ui_locale', 'de');
    $this->get(route('login'))->assertOk()->assertHeader('Content-Language', 'de')
        ->assertSee('html lang="de"', false)->assertSee('Passwort')->assertSee('Anmelden');
    expect(app()->getLocale())->toBe('en');
    $this->post(route('ui-language.update'), ['locale' => 'sv'])->assertRedirect();
    $this->get(route('login'))->assertOk()->assertHeader('Content-Language', 'sv')->assertSee('Lösenord');
});

test('language preference rejects invalid values and external redirects', function () {
    $this->from('https://external.example/login')->post(route('ui-language.update'), ['locale' => 'de'])
        ->assertRedirect(route('home', ['lang' => 'de']));
    $this->post(route('ui-language.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
    $this->post(route('ui-language.update'), ['locale' => ['de']])->assertSessionHasErrors('locale');
});

test('the German landing page and every demo view render with complete copy', function () {
    $this->get('/?lang=de')->assertOk()->assertSee('html lang="de"', false)->assertSee('Demo erkunden')->assertSee('Registrieren');
    foreach (['dashboard', 'catalogue', 'product', 'readiness', 'import', 'passport'] as $view) {
        $this->get('/demo?lang=de&view='.$view)->assertOk()->assertSee('Deutsch')->assertDontSee('showcase.');
    }
});

test('all shipped structured dictionaries have matching keys and interpolation placeholders', function () {
    $flatten = function (array $data, string $prefix = '') use (&$flatten): array {
        $result = [];
        foreach ($data as $key => $value) {
            $path = $prefix.$key;
            if (is_array($value)) {
                $result += $flatten($value, $path.'.');
            } else {
                $result[$path] = $value;
            }
        }

        return $result;
    };
    foreach (glob(lang_path('en/*.php')) as $file) {
        $base = $flatten(require $file);
        foreach (['sv', 'de'] as $locale) {
            $path = lang_path($locale.'/'.basename($file));
            expect(is_file($path))->toBeTrue($path);
            $translated = $flatten(require $path);
            $baseKeys = array_keys($base);
            $keys = array_keys($translated);
            sort($baseKeys);
            sort($keys);
            expect($keys)->toBe($baseKeys, $path);
            foreach ($base as $key => $value) {
                expect($translated[$key])->toBeString()->not->toBe('');
                preg_match_all('/(?<![\p{L}\p{N}_]):[a-zA-Z_][a-zA-Z0-9_]*/u', $value, $original);
                preg_match_all('/(?<![\p{L}\p{N}_]):[a-zA-Z_][a-zA-Z0-9_]*/u', $translated[$key], $localized);
                sort($original[0]);
                sort($localized[0]);
                expect($localized[0])->toBe($original[0], $path.':'.$key);
            }
        }
    }
});
