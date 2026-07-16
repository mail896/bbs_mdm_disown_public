<?php

function disown_is_dev_mode(): bool
{
    return basename(__DIR__) === 'disown-dev';
}

function disown_app_metadata(): array
{
    return [
        'version' => disown_is_dev_mode() ? '2.4-dev' : '2.4',
        'release_date' => '16. Juli 2026',
        'repository_url' => 'https://github.com/mail896/bbs_mdm_disown_public',
        'author_name' => 'Marc Schulz',
        'author_email' => 'marc.schulz@bbs-einbeck.de',
        'copyright_year' => '2026',
    ];
}

function disown_render_site_footer(string $section, array $options = []): void
{
    $metadata = disown_app_metadata();
    $className = trim((string) ($options['class'] ?? 'footer')) ?: 'footer';
    $dataStatus = trim((string) ($options['data_status'] ?? ''));
    $extraHtml = (string) ($options['extra_html'] ?? '');
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<footer class="' . $escape($className) . '"><span>';
    if ($dataStatus !== '') {
        echo 'Datenstand: ' . $escape($dataStatus) . ' · ';
    }
    echo '&copy; ' . $escape($metadata['copyright_year'])
        . ' <a href="mailto:' . $escape($metadata['author_email']) . '">' . $escape($metadata['author_name']) . '</a>'
        . ' · <a href="' . $escape($metadata['repository_url']) . '" target="_blank" rel="noopener noreferrer">Version ' . $escape($metadata['version']) . '</a>'
        . ' · Stand: ' . $escape($metadata['release_date']);
    if ($section !== '') {
        echo ' · ' . $escape($section);
    }
    echo '</span>' . $extraHtml . '</footer>';
}
