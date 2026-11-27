<?php

/**
 * Task Rules & Points Suggestion Helper
 */

if (!function_exists('task_rules_tables_exist')) {

    function task_rules_tables_exist() {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        try {
            $db = db_connect('default');
            $prefix = $db->getPrefix();
            $exists = ($db->tableExists($prefix . 'task_rules') || $db->tableExists('rise_task_rules'))
                   && ($db->tableExists($prefix . 'task_meta') || $db->tableExists('rise_task_meta'));
        } catch (\Throwable $e) {
            $exists = false;
        }

        return $exists;
    }

}

if (!function_exists('task_rules_departments')) {

    function task_rules_departments() {
        return array('Digital Marketing', 'Design', 'Video', 'Content', 'IT');
    }

}

if (!function_exists('task_rules_sub_departments')) {

    function task_rules_sub_departments() {
        return array('Social Media', 'SEO', 'Performance Marketing', 'Lead Ad', 'Google Ads');
    }

}

if (!function_exists('task_rules_normalize')) {

    function task_rules_normalize($str) {
        if (!$str || !is_string($str)) {
            return '';
        }
        $str = mb_strtolower($str, 'UTF-8');
        $str = str_replace('&', 'and', $str);
        $str = str_replace(array('-', '/', '\\', '_', '–', '|', ':', '(', ')', '[', ']', '{', '}'), ' ', $str);
        $str = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $str);
        $str = trim(preg_replace('/\s+/', ' ', $str));
        return $str;
    }

}

if (!function_exists('task_rules_stem')) {

    function task_rules_stem($word) {
        $word = str_replace('optimis', 'optimiz', $word);
        $len = strlen($word);
        if ($len > 4 && substr($word, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';
        }
        if ($len > 4 && substr($word, -2) === 'es' && !in_array(substr($word, -3), array('ses', 'xes', 'zes', 'ches', 'shes'))) {
            return substr($word, 0, -1);
        }
        if ($len > 3 && substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
            return substr($word, 0, -1);
        }
        return $word;
    }

}

if (!function_exists('task_rules_tokenize')) {

    function task_rules_tokenize($text) {
        $norm = task_rules_normalize($text);
        if ($norm === '') return array();
        $raw = explode(' ', $norm);
        $stop = array(
            'a' => true, 'an' => true, 'the' => true, 'of' => true, 'for' => true,
            'to' => true, 'and' => true, 'per' => true, 'one' => true, 'new' => true
        );
        $tokens = array();
        foreach ($raw as $w) {
            $w = trim($w);
            if ($w === '' || isset($stop[$w])) continue;
            $stem = task_rules_stem($w);
            if ($stem !== '' && !isset($stop[$stem])) {
                $tokens[] = $stem;
            }
        }
        return array_values(array_unique($tokens));
    }

}

if (!function_exists('task_rules_format_time')) {

    function task_rules_format_time($minutes) {
        $minutes = (int)$minutes;
        if ($minutes <= 0) return '';
        if ($minutes < 60) {
            return $minutes . " min";
        }
        $hours = floor($minutes / 60);
        $rem = $minutes % 60;
        if ($rem === 0) {
            return $hours . " h";
        }
        return $hours . " h " . $rem . " min";
    }

}

if (!function_exists('task_rules_calculate_end_time')) {

    function task_rules_calculate_end_time($minutes, $baseTimestamp = null) {
        try {
            $tz = new \DateTimeZone("Asia/Calcutta");
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone("UTC");
        }

        if ($baseTimestamp) {
            try {
                if (is_numeric($baseTimestamp)) {
                    $now = new \DateTime("now", $tz);
                    $now->setTimestamp((int)$baseTimestamp);
                } else {
                    $now = new \DateTime((string)$baseTimestamp, $tz);
                }
            } catch (\Throwable $e) {
                $now = new \DateTime("now", $tz);
            }
        } else {
            $now = new \DateTime("now", $tz);
        }

        $todayStr = $now->format("Y-m-d");

        $end = clone $now;
        $end->modify("+" . (int)$minutes . " minutes");

        $endDateStr = $end->format("Y-m-d");
        $passesMidnight = ($endDateStr !== $todayStr);

        return array(
            'current_time_str' => $now->format("h:i A"),
            'end_time_str' => $end->format("h:i A"),
            'end_time_24' => $end->format("H:i"),
            'today_date' => $todayStr,
            'deadline_date' => $endDateStr,
            'passes_midnight' => $passesMidnight
        );
    }

}

if (!function_exists('task_rules_match')) {

    function task_rules_match($dept, $subDept, $title, $contentType = null, $contentText = null, $baseTime = null) {
        $default_timing = task_rules_calculate_end_time(60, $baseTime);
        if (!task_rules_tables_exist()) {
            return array('decision' => 'none', 'top_match' => null, 'matches' => array(), 'default_timing' => $default_timing);
        }

        $dept = trim((string)$dept);
        $subDept = trim((string)$subDept);
        $title = trim((string)$title);
        $contentType = trim((string)$contentType);
        $contentText = trim((string)$contentText);

        if (!$dept) {
            return array('decision' => 'none', 'top_match' => null, 'matches' => array(), 'default_timing' => $default_timing);
        }

        // Build combined text for matching
        $matchQuery = $title;
        if ($contentType !== '') {
            $matchQuery = $contentType . ' ' . $matchQuery;
        }
        if ($contentText !== '') {
            $matchQuery .= ' ' . $contentText;
        }

        if (mb_strlen($matchQuery, 'UTF-8') < 3) {
            return array('decision' => 'none', 'top_match' => null, 'matches' => array(), 'default_timing' => $default_timing);
        }

        try {
            $db = db_connect('default');
            $prefix = $db->getPrefix();
            $tableName = $prefix . 'task_rules';

            $builder = $db->table($tableName)->where('department', $dept)->where('deleted', 0);
            if ($subDept !== '') {
                $builder->where('sub_department', $subDept);
            } else {
                $builder->groupStart()
                        ->where('sub_department', null)
                        ->orWhere('sub_department', '')
                        ->groupEnd();
            }

            $rules = $builder->get()->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'task_rules_match error: ' . $e->getMessage());
            return array('decision' => 'none', 'top_match' => null, 'matches' => array(), 'default_timing' => $default_timing);
        }

        if (empty($rules)) {
            return array('decision' => 'none', 'top_match' => null, 'matches' => array(), 'default_timing' => $default_timing);
        }

        $titleNorm = task_rules_normalize($matchQuery);
        $titleTokens = task_rules_tokenize($matchQuery);
        if (empty($titleTokens)) {
            return array('decision' => 'none', 'top_match' => null, 'matches' => array());
        }

        $contentTypeNorm = task_rules_normalize($contentType);
        $contentTypeTokens = task_rules_tokenize($contentType);

        // Slide counts detection
        $has3Slides = (bool) preg_match('/\b(3|three)\s*(slides?|pages?)?\b/i', $matchQuery);
        $has6Slides = (bool) preg_match('/\b(6|six)\s*(slides?|pages?)?\b/i', $matchQuery);

        $scored = array();
        foreach ($rules as $r) {
            $taskNorm = task_rules_normalize($r['task']);
            $taskTokens = task_rules_tokenize($r['task']);
            $kwNorm = task_rules_normalize($r['keywords'] ?? '');
            $kwTokens = task_rules_tokenize($r['keywords'] ?? '');

            if (empty($taskTokens)) continue;

            // 1. Share of rule's meaningful words found in title
            $overlapCount = 0;
            foreach ($taskTokens as $rt) {
                if (in_array($rt, $titleTokens, true)) {
                    $overlapCount++;
                }
            }
            $ruleShare = $overlapCount / count($taskTokens);

            // Share of title's meaningful words found in rule or keywords
            $allRuleTokens = array_unique(array_merge($taskTokens, $kwTokens));
            $titleOverlap = 0;
            foreach ($titleTokens as $tt) {
                if (in_array($tt, $allRuleTokens, true)) {
                    $titleOverlap++;
                }
            }
            $titleShare = count($titleTokens) > 0 ? ($titleOverlap / count($titleTokens)) : 0;

            // Content type weight bonus: if content type matches rule, give high bonus
            $contentBonus = 0;
            if (!empty($contentTypeTokens)) {
                $ctOverlap = 0;
                foreach ($contentTypeTokens as $ct) {
                    if (in_array($ct, $allRuleTokens, true)) {
                        $ctOverlap++;
                    }
                }
                if ($ctOverlap > 0) {
                    $contentBonus = 0.25 * ($ctOverlap / count($contentTypeTokens));
                }
            }

            $score = ($ruleShare * 0.55) + ($titleShare * 0.45) + $contentBonus;

            // 2. Exact phrase containment bonus
            if ($taskNorm !== '' && strpos($taskNorm, $titleNorm) !== false) {
                $score += 0.35;
            } else if ($titleNorm !== '' && strpos($titleNorm, $taskNorm) !== false) {
                $score += 0.35;
            }

            // Keyword phrase bonus
            if ($kwNorm !== '' && strpos($kwNorm, $titleNorm) !== false) {
                $score += 0.25;
            }

            // Carousel specific slide checks
            $isCarousel3 = strpos($taskNorm, 'carousel 3 slides') !== false || strpos($taskNorm, 'carousel 3') !== false;
            $isCarousel6 = strpos($taskNorm, 'carousel 6 slides') !== false || strpos($taskNorm, 'carousel 6') !== false;

            if ($isCarousel3 && $has3Slides) {
                $score += 0.45;
            } else if ($isCarousel3 && $has6Slides) {
                $score -= 0.45;
            } else if ($isCarousel6 && $has6Slides) {
                $score += 0.45;
            } else if ($isCarousel6 && $has3Slides) {
                $score -= 0.45;
            }

            if ($score > 1.0) $score = 1.0;
            if ($score < 0.0) $score = 0.0;

            if ($score >= 0.30) {
                $scored[] = array(
                    'rule' => $r,
                    'score' => round($score, 3)
                );
            }
        }

        // Sort by score DESC
        usort($scored, function($a, $b) {
            if ($b['score'] == $a['score']) {
                return 0;
            }
            return ($b['score'] > $a['score']) ? 1 : -1;
        });

        if (empty($scored)) {
            return array('decision' => 'none', 'top_match' => null, 'matches' => array());
        }

        $top = $scored[0];
        $second = isset($scored[1]) ? $scored[1] : null;

        $decision = 'none';
        if ($top['score'] >= 0.50) {
            if (!$second || ($top['score'] - $second['score']) >= 0.15) {
                $decision = 'apply';
            } else {
                $decision = 'suggest';
            }
        } else if ($top['score'] >= 0.35) {
            $decision = 'suggest';
        }

        $matches = array_slice($scored, 0, 5);
        $cleanMatches = array();
        foreach ($matches as $m) {
            $timeFmt = $m['rule']['minutes_max'] ? task_rules_format_time($m['rule']['minutes_max']) : '';
            $ptsMin = $m['rule']['points_min'] !== null ? (int)$m['rule']['points_min'] : null;
            $ptsMax = $m['rule']['points_max'] !== null ? (int)$m['rule']['points_max'] : null;
            $minMin = $m['rule']['minutes_min'] !== null ? (int)$m['rule']['minutes_min'] : null;
            $minMax = $m['rule']['minutes_max'] !== null ? (int)$m['rule']['minutes_max'] : null;

            $timing = null;
            if ($minMax !== null) {
                $timing = task_rules_calculate_end_time($minMax, $baseTime);
            }

            $cleanMatches[] = array(
                'id' => (int)$m['rule']['id'],
                'task' => $m['rule']['task'],
                'department' => $m['rule']['department'],
                'sub_department' => $m['rule']['sub_department'],
                'group_name' => $m['rule']['group_name'],
                'points_min' => $ptsMin,
                'points_max' => $ptsMax,
                'minutes_min' => $minMin,
                'minutes_max' => $minMax,
                'time_formatted' => $timeFmt,
                'timing' => $timing,
                'score' => $m['score']
            );
        }

        return array(
            'decision' => $decision,
            'top_match' => $cleanMatches[0] ?? null,
            'matches' => $cleanMatches,
            'default_timing' => $default_timing
        );
    }

}
