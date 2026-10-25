<?php

declare(strict_types=1);

if (!function_exists('faoxima_manualsale_ext_is_file')) {
    function faoxima_manualsale_ext_is_file(string $ext): bool
    {
        $ext = strtolower(ltrim(trim($ext), '.'));
        if ($ext === '' || $ext === 'sub' || $ext === 'text') {
            return false;
        }
        if (function_exists('rxManualsaleExtIsFile')) {
            return (bool)rxManualsaleExtIsFile($ext);
        }
        return true;
    }
}

if (!function_exists('faoxima_manualsale_public_filename')) {
    function faoxima_manualsale_public_filename(string $name, string $ext): string
    {
        if (function_exists('rxManualsalePublicName')) {
            return rxManualsalePublicName($name, $ext);
        }
        $ext = strtolower(ltrim(trim($ext), '.'));
        if ($ext === '') {
            $ext = 'bin';
        }
        $stem = trim($name);
        $suffix = '.' . $ext;
        if ($stem !== '' && strtolower(substr($stem, -strlen($suffix))) === $suffix) {
            $stem = substr($stem, 0, -strlen($suffix));
        }
        return function_exists('normalizeConfigFilename')
            ? normalizeConfigFilename($stem . $suffix, '', $ext === 'conf')
            : ($stem !== '' ? $stem : 'config') . $suffix;
    }
}

if (!function_exists('faoxima_build_service_output')) {
    function faoxima_build_service_output(array $opts): array
    {
        $panelType = (string)($opts['panel_type'] ?? '');
        $content   = (string)($opts['content'] ?? '');
        $subLink   = trim((string)($opts['sub_link'] ?? ''));
        $fileExt   = strtolower(ltrim(trim((string)($opts['file_ext'] ?? '')), '.'));
        $recordName = trim((string)($opts['name'] ?? ''));

        $configs = [];
        if (isset($opts['configs']) && is_array($opts['configs'])) {
            foreach ($opts['configs'] as $c) {
                if (is_string($c) && trim($c) !== '') {
                    $configs[] = $c;
                }
            }
        }

        $items = [];
        if (isset($opts['items']) && is_array($opts['items'])) {
            foreach ($opts['items'] as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $itContent = (string)($it['content'] ?? '');
                if (trim($itContent) === '') {
                    continue;
                }
                $items[] = [
                    'content'  => $itContent,
                    'file_ext' => strtolower(ltrim(trim((string)($it['file_ext'] ?? '')), '.')),
                    'sub_link' => trim((string)($it['sub_link'] ?? '')),
                    'name'     => trim((string)($it['name'] ?? '')),
                ];
            }
        }
        if ($recordName === '' && !empty($items)) {
            $recordName = $items[0]['name'];
        }

        $output = [];

        if ($panelType === 'Manualsale') {
            if (count($items) > 1) {
                $unifiedSub = $subLink;
                if ($unifiedSub === '') {
                    foreach ($items as $it) {
                        if ($it['sub_link'] !== '') {
                            $unifiedSub = $it['sub_link'];
                            break;
                        }
                    }
                }
                if ($unifiedSub !== '') {
                    $output[] = ['type' => 'link', 'value' => $unifiedSub];
                }
                foreach ($items as $it) {
                    $itExt = $it['file_ext'];
                    $itContent = $it['content'];
                    if ($itExt === 'sub') {
                        $linkValue = $itContent !== '' ? $itContent : $it['sub_link'];
                        if ($linkValue !== '') {
                            $output[] = ['type' => 'link', 'value' => $linkValue];
                        }
                        continue;
                    }
                    if (faoxima_manualsale_ext_is_file($itExt)) {
                        $ext = ($itExt !== '') ? $itExt : 'bin';
                        $output[] = [
                            'type'     => 'file',
                            'value'    => 'data:application/octet-stream;base64,' . base64_encode($itContent),
                            'filename' => faoxima_manualsale_public_filename($it['name'], $ext),
                        ];
                    } else {
                        $output[] = ['type' => 'text', 'value' => $itContent];
                    }
                }
                return $output;
            }

            $isLink = $fileExt === 'sub';
            $isFile = faoxima_manualsale_ext_is_file($fileExt);

            if ($isLink) {
                $linkValue = $content !== '' ? $content : $subLink;
                if ($linkValue !== '') {
                    $output[] = ['type' => 'link', 'value' => $linkValue];
                }
                return $output;
            }

            if ($subLink !== '') {
                $output[] = ['type' => 'link', 'value' => $subLink];
            }

            if ($content !== '') {
                if ($isFile) {
                    $ext = ($fileExt !== '') ? $fileExt : 'bin';
                    $output[] = [
                        'type'     => 'file',
                        'value'    => 'data:application/octet-stream;base64,' . base64_encode($content),
                        'filename' => faoxima_manualsale_public_filename($recordName, $ext),
                    ];
                } else {
                    $output[] = ['type' => 'text', 'value' => $content];
                }
            } elseif (!empty($configs)) {
                $output[] = ['type' => 'text', 'value' => $configs[0]];
            }

            return $output;
        }

        if ($subLink !== '') {
            $output[] = ['type' => 'link', 'value' => $subLink];
        }

        $configItems = [];
        foreach ($configs as $c) {
            if ($c !== $subLink) {
                $configItems[] = $c;
            }
        }
        if (empty($configItems) && $content !== '' && $content !== $subLink) {
            $configItems[] = $content;
        }
        if (!empty($configItems)) {
            $output[] = ['type' => 'config', 'value' => $configItems];
        }

        return $output;
    }
}
