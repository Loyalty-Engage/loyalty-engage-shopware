<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

final class PersonalizationConfig
{
    private const UUID = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}';

    public function __construct(private readonly LoyaltyShopConfig $config) {}

    public static function tenant(string $input): string
    {
        $input = trim($input);
        if ($input === '') { return ''; }
        if (preg_match('/^' . self::UUID . '$/iD', $input)) { return strtolower($input); }

        // Extract a UUID from known loader forms; never execute or persist pasted JavaScript.
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<html><body>' . $input . '</body></html>', LIBXML_NONET);
            $scripts = $document->getElementsByTagName('script');
            if ($scripts->length !== 1) {
                throw new \InvalidArgumentException('Use a tenant UUID or the official loader script.');
            }
            $script = $scripts->item(0);
            foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) {
                if ($node !== $script && ($node->nodeType !== XML_TEXT_NODE || trim($node->textContent) !== '')) {
                    throw new \InvalidArgumentException('Only the official loader script is allowed.');
                }
            }
            foreach ($script->attributes as $attribute) {
                if (!in_array(strtolower($attribute->name), ['src', 'type', 'async'], true)) {
                    throw new \InvalidArgumentException('Unexpected loader script attribute.');
                }
            }
            $code = trim($script->textContent);
            if ($code !== '') {
                if ($script->hasAttribute('src') || $script->hasAttribute('async')
                    || !in_array(strtolower($script->getAttribute('type')), ['', 'text/javascript'], true)) {
                    throw new \InvalidArgumentException('Invalid official loader snippet.');
                }
                return self::inlineTenant($code);
            }
            if (!preg_match('~^https://le-cloud\.app/f/t/(' . self::UUID . ')\.js$~iD', $script->getAttribute('src'), $match)) {
                throw new \InvalidArgumentException('The loader must use https://le-cloud.app/f/t/<tenant UUID>.js.');
            }
            return strtolower($match[1]);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function inlineTenant(string $code): string
    {
        // Whitelist the official bootstrap's complete shape, not just any script containing a UUID.
        $template = <<<'JS'
((a,b,c,d,e,f)=>{a._lecloud=a._lecloud||{event:[],personalization:[]};e=b.getElementsByTagName(c)[0];f=b.createElement(c);f.type="module";f.async=!0;f.src="https://le-cloud.app/f/t/"+d+".js";e.parentNode.insertBefore(f,e);})(window,document,"script","__TENANT__");
JS;
        $compact = preg_replace('/\s+/', '', str_replace("'", '"', $code));
        $pattern = str_replace('__TENANT__', '(' . self::UUID . ')', preg_quote($template, '~'));
        if (!preg_match('~^' . $pattern . '$~iD', $compact, $match)) {
            throw new \InvalidArgumentException('Only the official Loyalty Engage bootstrap snippet is allowed.');
        }
        return strtolower($match[1]);
    }

    public static function normalizeRules(mixed $rules): array
    {
        if ($rules === null || $rules === '') { return []; }
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 100) {
            throw new \InvalidArgumentException('Personalizations must be a list of at most 100 rules.');
        }
        $result = [];
        $keys = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) { throw new \InvalidArgumentException('Invalid personalization rule.'); }
            $key = $rule['key'] ?? '';
            $id = $rule['public_id'] ?? '';
            $placement = $rule['placement'] ?? 'manual';
            $scope = $rule['page_scope'] ?? 'product';
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key) || isset($keys[$key])) {
                throw new \InvalidArgumentException('Each personalization needs a unique lowercase key (letters, digits, - and _).');
            }
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}$/D', $id)) {
                throw new \InvalidArgumentException('Each personalization needs a valid Public ID.');
            }
            if (!in_array($placement, ['manual', 'automatic'], true) || !in_array($scope, ['product', 'cart', 'checkout', 'pages'], true)) {
                throw new \InvalidArgumentException('Invalid personalization placement or page scope.');
            }
            $pages = $rule['pages'] ?? [];
            if (!is_array($pages) || !array_is_list($pages) || count($pages) > 100) {
                throw new \InvalidArgumentException('Selected pages must be a list of CMS page UUIDs.');
            }
            foreach ($pages as $page) {
                if (!is_string($page) || !preg_match('/^[a-f0-9]{32}$/iD', $page)) {
                    throw new \InvalidArgumentException('Select CMS pages by their Shopware UUID.');
                }
            }
            $booleans = [];
            foreach (['enabled', 'personalized', 'use_cart_value'] as $field) {
                $value = $rule[$field] ?? false;
                if (!is_bool($value)) { throw new \InvalidArgumentException('Personalization switches must be booleans.'); }
                $booleans[$field] = $value;
            }
            $keys[$key] = true;
            $result[] = $booleans + [
                'key' => $key, 'public_id' => $id, 'placement' => $placement,
                'page_scope' => $scope, 'pages' => array_values(array_unique(array_map('strtolower', $pages))),
            ];
        }
        return $result;
    }

    public function storefront(string $channel): array
    {
        try {
            $tenant = self::tenant((string) $this->config->get('personalizationTenant', $channel, ''));
            $rules = self::normalizeRules($this->config->get('personalizationRules', $channel, []));
        } catch (\InvalidArgumentException) {
            return ['tenant' => '', 'rules' => [], 'debug' => false];
        }
        return [
            'tenant' => $tenant,
            'rules' => $tenant === '' ? [] : array_values(array_filter($rules, static fn (array $rule): bool => $rule['enabled'])),
            'debug' => (bool) $this->config->get('personalizationDebug', $channel, false),
        ];
    }
}
