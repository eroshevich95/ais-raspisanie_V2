<?php
// includes/departments.php

if (!function_exists('getKuzstuDepartments')) {

    function getKuzstuDepartments(): array {
        $cacheDir  = __DIR__ . '/../cache';
        $cacheFile = $cacheDir . '/departments.json';
        $cacheTtl  = 86400;

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)
                && !empty($cached['institutes'])
                && !empty($cached['departments'])
                && is_array($cached['departments'])) {
                return $cached;
            }
        }

        $api = fetchDepartmentsFromApi();
        if ($api !== null
            && !empty($api['institutes'])
            && !empty($api['departments'])) {
            saveDepartmentsCache($cacheFile, $api);
            return $api;
        }

        return getFallbackDepartments();
    }

    function fetchDepartmentsFromApi(): ?array {
        $baseUrl = 'https://portal.kuzstu.ru/api';

        $institutesRaw = httpGetJson($baseUrl . '/institutes');
        if (!is_array($institutesRaw) || empty($institutesRaw)) return null;

        $institutes    = [];
        $rawInstitutes = [];

        foreach ($institutesRaw as $inst) {
            $id   = (string)($inst['id'] ?? '');
            $name = trim((string)($inst['name'] ?? ''));
            $abbr = trim((string)($inst['abbr'] ?? ''));
            if ($id === '' || $name === '') continue;

            $label = $abbr !== '' ? ($abbr . ' — ' . $name) : $name;
            $institutes[$id]  = $label;
            $rawInstitutes[]  = ['id' => $id, 'name' => $name, 'abbr' => $abbr];
        }

        if (empty($institutes)) return null;

        $departments = [];
        foreach ($rawInstitutes as $inst) {
            $instId = $inst['id'];
            $list   = fetchDepartmentsForInstitute($baseUrl, $instId);
            if (!empty($list)) {
                usort($list, fn($a, $b) => strcoll($a['name'], $b['name']));
                $departments[$instId] = $list;
            }
        }

        if (empty($departments)) return null;

        return [
            'institutes'  => $institutes,
            'departments' => $departments,
        ];
    }

    function fetchDepartmentsForInstitute(string $baseUrl, string $instituteId): array {
        $url  = $baseUrl . '/departments?institute=' . urlencode($instituteId);
        $data = httpGetJson($url);

        if (!is_array($data)) return [];

        $result = [];
        foreach ($data as $item) {
            if (!is_array($item)) continue;
            $id   = (string)($item['id'] ?? '');
            $name = trim((string)($item['name'] ?? ''));
            $abbr = trim((string)($item['abbr'] ?? ''));
            if ($id === '' || $name === '') continue;

            $result[] = [
                'id'    => $id,
                'name'  => $name,
                'abbr'  => $abbr,
                'label' => $abbr !== '' ? ($abbr . ' — ' . $name) : $name,
            ];
        }

        return $result;
    }

    function httpGetJson(string $url): ?array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'AIS-Raspisanie/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return null;

        $json = json_decode($response, true);
        return is_array($json) ? $json : null;
    }

    function saveDepartmentsCache(string $file, array $data): void {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    function getFallbackDepartments(): array {
        return [
            'institutes' => [
                '483'  => 'ГИ — Горный институт',
                '496'  => 'ИЭ — Институт энергетики',
                '501'  => 'ИЭиУ — Институт экономики и управления',
                '515'  => 'ИХНТ — Институт химических и нефтегазовых технологий',
                '2481' => 'СИ — Строительный институт',
                '4083' => 'ИИТМА — Институт информационных технологий, машиностроения и автотранспорта',
                '5625' => 'ИПО — Институт профессионального образования',
                '6866' => 'Управление физической культуры и спорта',
            ],
            'departments' => [
                '483' => [
                    ['id' => 'gi_gd',  'name' => 'Кафедра геологии и разведки полезных ископаемых', 'abbr' => 'ГиРПИ'],
                    ['id' => 'gi_md',  'name' => 'Кафедра маркшейдерского дела и геодезии',          'abbr' => 'МДиГ'],
                    ['id' => 'gi_gm',  'name' => 'Кафедра горных машин и комплексов',                'abbr' => 'ГМиК'],
                    ['id' => 'gi_ogr', 'name' => 'Кафедра открытых горных работ',                    'abbr' => 'ОГР'],
                    ['id' => 'gi_pg',  'name' => 'Кафедра подземной разработки месторождений',        'abbr' => 'ПРМ'],
                    ['id' => 'gi_aer', 'name' => 'Кафедра аэрологии, охраны труда и природы',        'abbr' => 'АОТП'],
                ],
                '496' => [
                    ['id' => 'ie_ees', 'name' => 'Кафедра электроснабжения',                         'abbr' => 'ЭС'],
                    ['id' => 'ie_tep', 'name' => 'Кафедра теплоэнергетики',                          'abbr' => 'ТЭ'],
                    ['id' => 'ie_ep',  'name' => 'Кафедра электротехники и электрооборудования',     'abbr' => 'ЭЭ'],
                    ['id' => 'ie_epp', 'name' => 'Кафедра электропривода и автоматизации',           'abbr' => 'ЭПиА'],
                    ['id' => 'ie_aep', 'name' => 'Кафедра автоматизации энергетических процессов',   'abbr' => 'АЭП'],
                    ['id' => 'ie_pi',  'name' => 'Кафедра прикладной информатики',                   'abbr' => 'ПИ'],
                ],
                '501' => [
                    ['id' => 'ieu_eup', 'name' => 'Кафедра экономики и управления производством',    'abbr' => 'ЭУП'],
                    ['id' => 'ieu_bua', 'name' => 'Кафедра бухгалтерского учёта и аудита',           'abbr' => 'БУА'],
                    ['id' => 'ieu_fin', 'name' => 'Кафедра финансов и кредита',                      'abbr' => 'ФиК'],
                    ['id' => 'ieu_men', 'name' => 'Кафедра менеджмента',                             'abbr' => 'Мен'],
                    ['id' => 'ieu_it',  'name' => 'Кафедра информационных технологий в экономике',   'abbr' => 'ИТЭ'],
                ],
                '515' => [
                    ['id' => 'ihnt_ht',  'name' => 'Кафедра химической технологии',                          'abbr' => 'ХТ'],
                    ['id' => 'ihnt_ngt', 'name' => 'Кафедра нефтегазовых технологий',                        'abbr' => 'НГТ'],
                    ['id' => 'ihnt_oh',  'name' => 'Кафедра органической химии',                             'abbr' => 'ОХ'],
                    ['id' => 'ihnt_fh',  'name' => 'Кафедра физической химии',                               'abbr' => 'ФХ'],
                    ['id' => 'ihnt_ebj', 'name' => 'Кафедра экологии и безопасности жизнедеятельности',      'abbr' => 'ЭБЖ'],
                ],
                '2481' => [
                    ['id' => 'si_sk',  'name' => 'Кафедра строительных конструкций',                 'abbr' => 'СК'],
                    ['id' => 'si_st',  'name' => 'Кафедра строительных технологий и материалов',     'abbr' => 'СТМ'],
                    ['id' => 'si_pb',  'name' => 'Кафедра промышленной безопасности',                'abbr' => 'ПБ'],
                    ['id' => 'si_arh', 'name' => 'Кафедра архитектуры',                              'abbr' => 'Арх'],
                    ['id' => 'si_os',  'name' => 'Кафедра оснований и фундаментов',                  'abbr' => 'ОиФ'],
                ],
                '4083' => [
                    ['id' => 'iitma_vt',  'name' => 'Кафедра вычислительной техники и информационных технологий', 'abbr' => 'ВТиИТ'],
                    ['id' => 'iitma_asu', 'name' => 'Кафедра автоматизированных систем управления',               'abbr' => 'АСУ'],
                    ['id' => 'iitma_pm',  'name' => 'Кафедра прикладной математики',                              'abbr' => 'ПМ'],
                    ['id' => 'iitma_tm',  'name' => 'Кафедра технологии машиностроения',                          'abbr' => 'ТМ'],
                    ['id' => 'iitma_at',  'name' => 'Кафедра автомобилей и автомобильного хозяйства',             'abbr' => 'ААХ'],
                    ['id' => 'iitma_it',  'name' => 'Кафедра информационных технологий',                          'abbr' => 'ИТ'],
                ],
                '5625' => [
                    ['id' => 'ipo_do',  'name' => 'Отделение дополнительного образования',          'abbr' => 'ДО'],
                    ['id' => 'ipo_ppo', 'name' => 'Центр повышения квалификации преподавателей',    'abbr' => 'ЦПКП'],
                    ['id' => 'ipo_po',  'name' => 'Кафедра профессионального обучения',             'abbr' => 'ПО'],
                ],
                '6866' => [
                    ['id' => 'ufks_fk',  'name' => 'Кафедра физической культуры',                   'abbr' => 'ФК'],
                    ['id' => 'ufks_s',   'name' => 'Кафедра спорта',                                'abbr' => 'Спорт'],
                    ['id' => 'ufks_afk', 'name' => 'Кафедра адаптивной физической культуры',        'abbr' => 'АФК'],
                ],
            ],
        ];
    }
}