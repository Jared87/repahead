<?php

declare(strict_types=1);

namespace RepAhead\Http;

/**
 * Renders the human-facing landing page from the decoded packages.json Index:
 * one card per Package, listing its Releases newest-first with a link to each
 * Release ZIP. Satis-style — static HTML, no JavaScript, no external assets.
 */
final class IndexView
{
    /**
     * @param array<string, array<string, array<string, mixed>>> $packages
     *        Index `packages` map: name => (version => composer.json payload).
     */
    public static function render(array $packages, string $baseUrl): string
    {
        $cards = '';
        foreach ($packages as $name => $versions) {
            $cards .= self::package((string) $name, $versions);
        }
        if ($cards === '') {
            $cards = '<p class="empty">No packages published yet.</p>';
        }

        return self::document(count($packages), $baseUrl, $cards);
    }

    /** @param array<string, array<string, mixed>> $versions */
    private static function package(string $name, array $versions): string
    {
        // packages.json sorts versions lexically; present them semver newest-first.
        $ordered = $versions;
        uksort($ordered, static fn ($a, $b): int => version_compare($b, $a));
        $firstKey = array_key_first($ordered);
        $latest = $firstKey === null ? [] : $ordered[$firstKey];

        $description = isset($latest['description']) && is_string($latest['description'])
            ? '<p class="desc">' . self::e($latest['description']) . '</p>'
            : '';

        $rows = '';
        foreach ($ordered as $version => $info) {
            $rows .= self::version((string) $version, $info);
        }

        $safeName = self::e($name);
        $command = 'composer require ' . $name;
        $require = self::e($command);
        $size = strlen($command) + 1;
        return <<<HTML
        <article class="pkg">
            <div class="pkg-head">
                <h2>{$safeName}</h2>
                <input class="copy" type="text" readonly size="{$size}" value="{$require}" aria-label="Install command, click to copy">
            </div>
            {$description}
            <ul class="versions">{$rows}</ul>
        </article>

        HTML;
    }

    /** @param array<string, mixed> $info */
    private static function version(string $version, array $info): string
    {
        $label = self::e($version);
        $dist = $info['dist'] ?? null;
        $url = is_array($dist) && isset($dist['url']) && is_string($dist['url']) ? $dist['url'] : null;

        if ($url === null) {
            return "<li><span class=\"ver\">{$label}</span></li>";
        }

        $href = self::e($url);
        return "<li><span class=\"ver\">{$label}</span> <a href=\"{$href}\">zip</a></li>";
    }

    private static function document(int $count, string $baseUrl, string $cards): string
    {
        $host = self::e($baseUrl);
        $plural = $count === 1 ? 'package' : 'packages';
        $snippet = self::e(self::repositorySnippet($baseUrl));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$host}</title>
            <style>
                :root { color-scheme: light dark; }
                * { box-sizing: border-box; }
                body {
                    font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    margin: 0; padding: 2rem 1rem; max-width: 60rem; margin-inline: auto;
                    color: #1a1a1a; background: #fafafa;
                }
                @media (prefers-color-scheme: dark) {
                    body { color: #e6e6e6; background: #161616; }
                    .pkg { background: #1f1f1f; border-color: #333; }
                    pre, .copy { background: #111; }
                    .copy { border-color: #333; color: #e6e6e6; }
                }
                header { margin-bottom: 2rem; }
                h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
                .meta { color: #888; margin: 0; }
                pre {
                    background: #f0f0f0; padding: .75rem 1rem; border-radius: 6px;
                    overflow-x: auto; font-size: 13px; margin: 1rem 0 0;
                }
                .pkg {
                    background: #fff; border: 1px solid #e5e5e5; border-radius: 8px;
                    padding: 1rem 1.25rem; margin-bottom: 1rem;
                }
                .pkg-head {
                    display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem;
                    justify-content: space-between;
                }
                .pkg h2 { font-size: 1.1rem; margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
                .copy {
                    font: 13px/1 ui-monospace, SFMono-Regular, Menlo, monospace;
                    background: #f6f6f6; border: 1px solid #ddd; border-radius: 6px;
                    padding: .4rem .6rem; color: #1a1a1a; cursor: pointer;
                    flex: 0 1 auto; max-width: 100%;
                }
                .copy:hover { border-color: #2563eb; }
                .copy.copied { border-color: #16a34a; color: #16a34a; }
                .desc { color: #666; margin: .35rem 0 .75rem; }
                .versions { list-style: none; margin: 0; padding: 0;
                    display: flex; flex-wrap: wrap; gap: .4rem; }
                .versions li {
                    display: inline-flex; align-items: baseline; gap: .4rem;
                    border: 1px solid #ddd; border-radius: 999px; padding: .15rem .65rem;
                }
                @media (prefers-color-scheme: dark) { .versions li { border-color: #3a3a3a; } }
                .ver { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
                .versions a { text-decoration: none; color: #2563eb; font-size: 12px; }
                .versions a:hover { text-decoration: underline; }
                .empty { color: #888; }
            </style>
        </head>
        <body>
            <header>
                <h1>{$host}</h1>
                <p class="meta">{$count} {$plural}</p>
                <pre>{$snippet}</pre>
            </header>
            {$cards}
            <script>
                document.addEventListener('click', function (e) {
                    var input = e.target.closest('.copy');
                    if (!input) return;
                    input.select();
                    navigator.clipboard && navigator.clipboard.writeText(input.value);
                    input.classList.add('copied');
                    setTimeout(function () { input.classList.remove('copied'); }, 1000);
                });
            </script>
        </body>
        </html>

        HTML;
    }

    private static function repositorySnippet(string $baseUrl): string
    {
        return (string) json_encode(
            ['repositories' => [['type' => 'composer', 'url' => $baseUrl]]],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
