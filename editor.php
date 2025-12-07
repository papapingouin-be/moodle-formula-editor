<?php
// formula_editor_v18.php
// Éditeur Formula + SVG + script global (séparé) – avec LOG JS
// - Remplacement fiable de <!-- IMAGE 1 --> par le SVG
// - Export XML en fichier
// - Export CSV en fichier

mb_internal_encoding('UTF-8');

function get_param($name) {
    return isset($_POST[$name]) ? trim($_POST[$name]) : '';
}

$action = isset($_POST['action']) ? $_POST['action'] : 'generate';

$qname       = '';
$intro_html  = '';
$svg_source  = '';
$js_source   = '';
$varsrandom  = '';
$varsglobal  = '';
$config_main = '';
$svg_main_id = '';

$questiontext_html = '';
$xml_skeleton      = '';

$MAX_PARTS = 5;

$parts = [];
for ($i = 0; $i < $MAX_PARTS; $i++) {
    $parts[$i] = [
        'use'                      => true,
        'partindex'                => (string)$i,
        'placeholder'              => '',
        'answermark'               => '1',
        'answertype'               => '0',
        'numbox'                   => '1',
        'vars1'                    => '',
        'answer'                   => '',
        'vars2'                    => '',
        'correctness'              => '',
        'unitpenalty'              => '1',
        'postunit'                 => '',
        'ruleid'                   => '1',
        'otherrule'                => '',
        'subqtext'                 => '',
        'subq_svg'                 => '',
        'feedback'                 => '',
        'correctfeedback'          => '',
        'partiallycorrectfeedback' => '',
        'incorrectfeedback'        => '',
        'scriptparams_q'           => '',
        'scriptparams_fb'          => '',
        'imgid_q'                  => '',
        'imgid_fb'                 => ''
    ];
}

// valeurs par défaut pour les ids d’image
for ($i = 0; $i < $MAX_PARTS; $i++) {
    $idx = $i + 1;
    if ($parts[$i]['imgid_q']  === '') $parts[$i]['imgid_q']  = 'circuit' . $idx . 'a';
    if ($parts[$i]['imgid_fb'] === '') $parts[$i]['imgid_fb'] = 'circuit' . $idx . 'b';
}

function indent_snippet($text, $spaces) {
    $pad   = str_repeat(' ', $spaces);
    $lines = preg_split('/\R/', $text);
    $lines = array_map(function($l) use ($pad) { return $pad . rtrim($l); }, $lines);
    return implode("\n", $lines);
}

function build_config_entry($id, $snippet) {
    $snippet = trim($snippet);
    if ($snippet === '') return '';
    $snippet = "// [CFG:$id]\n" . $snippet;
    return "    " . $id . ": {\n" . indent_snippet($snippet, 8) . "\n    }";
}

function extract_circuit_snippet($js, $id) {
    $pattern = '/'.$id.'\s*:\s*\{([\s\S]*?GROUPS:[\s\S]*?\])\s*\}/i';
    if (preg_match($pattern, $js, $m)) {
        $snippet = trim($m[1]);
        $snippet = preg_replace('/^\s*\/\/\s*\[CFG:'.preg_quote($id, '/').'\]\s*\R/i', '', $snippet);
        return $snippet;
    }
    return '';
}

// CHARGEMENT DEPUIS POST (hors load_xml)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'load_xml') {
    $qname       = get_param('qname');
    $intro_html  = get_param('intro_html');
    $svg_source  = get_param('svg_source');
    $js_source   = get_param('js_source');
    $varsrandom  = get_param('varsrandom');
    $varsglobal  = get_param('varsglobal');
    $config_main = get_param('config_main');
    $svg_main_id = get_param('svg_main_id');

    if ($svg_main_id === '') {
        $svg_main_id = 'circuit1';
    }

    for ($i = 0; $i < $MAX_PARTS; $i++) {
        $parts[$i]['use']                      = isset($_POST["use_part_$i"]);
        $parts[$i]['partindex']                = get_param("partindex_$i") !== '' ? get_param("partindex_$i") : (string)$i;
        $parts[$i]['placeholder']              = get_param("placeholder_$i");
        $parts[$i]['answermark']               = get_param("answermark_$i");
        $parts[$i]['answertype']               = get_param("answertype_$i");
        $parts[$i]['numbox']                   = get_param("numbox_$i");
        $parts[$i]['vars1']                    = get_param("vars1_$i");
        $parts[$i]['answer']                   = get_param("answer_$i");
        $parts[$i]['vars2']                    = get_param("vars2_$i");
        $parts[$i]['correctness']              = get_param("correctness_$i");
        $parts[$i]['unitpenalty']              = get_param("unitpenalty_$i");
        $parts[$i]['postunit']                 = get_param("postunit_$i");
        $parts[$i]['ruleid']                   = get_param("ruleid_$i");
        $parts[$i]['otherrule']                = get_param("otherrule_$i");

        $parts[$i]['subqtext']                 = get_param("subqtext_$i");
        $parts[$i]['subq_svg']                 = get_param("subq_svg_$i");
        $parts[$i]['feedback']                 = get_param("feedback_$i");
        $parts[$i]['correctfeedback']          = get_param("correctfeedback_$i");
        $parts[$i]['partiallycorrectfeedback'] = get_param("partiallycorrectfeedback_$i");
        $parts[$i]['incorrectfeedback']        = get_param("incorrectfeedback_$i");

        $parts[$i]['scriptparams_q']           = get_param("scriptparams_q_$i");
        $parts[$i]['scriptparams_fb']          = get_param("scriptparams_fb_$i");

        $parts[$i]['imgid_q']                  = get_param("imgid_q_$i");
        $parts[$i]['imgid_fb']                 = get_param("imgid_fb_$i");

        $idx = $i + 1;
        if ($parts[$i]['imgid_q']  === '') $parts[$i]['imgid_q']  = 'circuit' . $idx . 'a';
        if ($parts[$i]['imgid_fb'] === '') $parts[$i]['imgid_fb'] = 'circuit' . $idx . 'b';
    }
}

// ACTIONS
if ($action === 'load_xml' && isset($_FILES['xmlfile']) && is_uploaded_file($_FILES['xmlfile']['tmp_name'])) {

    $xmlfile = $_FILES['xmlfile']['tmp_name'];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($xmlfile);
    if ($xml !== false) {
        $qnode = null;
        foreach ($xml->question as $q) {
            $attrs = $q->attributes();
            if (isset($attrs['type']) && (string)$attrs['type'] === 'formulas') {
                $qnode = $q; break;
            }
        }
        if ($qnode) {
            $qname   = (string)$qnode->name->text;
            $qt_raw  = (string)$qnode->questiontext->text;

            $intro_html = $qt_raw;

            if (preg_match('#(<svg\b.*?</svg>)#si', $qt_raw, $m)) {
                $svg_source = $m[1];
                $intro_html = str_replace($m[1], '[SVG_1]', $intro_html);
            }

            if (preg_match('#(<script\b.*?</script>)#si', $qt_raw, $m2)) {
                $js_source  = $m2[1];
                $intro_html = str_replace($m2[1], '', $intro_html);
            }

            if (isset($qnode->varsrandom->text)) {
                $varsrandom = (string)$qnode->varsrandom->text;
            } elseif (isset($qnode->varsrandom)) {
                $varsrandom = (string)$qnode->varsrandom;
            }

            if (isset($qnode->varsglobal->text)) {
                $varsglobal = (string)$qnode->varsglobal->text;
            } elseif (isset($qnode->varsglobal)) {
                $varsglobal = (string)$qnode->varsglobal;
            }

            $parts = [];
            for ($i = 0; $i < $MAX_PARTS; $i++) {
                $idx = $i + 1;
                $parts[$i] = [
                    'use'                      => false,
                    'partindex'                => (string)$i,
                    'placeholder'              => '',
                    'answermark'               => '1',
                    'answertype'               => '0',
                    'numbox'                   => '1',
                    'vars1'                    => '',
                    'answer'                   => '',
                    'vars2'                    => '',
                    'correctness'              => '',
                    'unitpenalty'              => '1',
                    'postunit'                 => '',
                    'ruleid'                   => '1',
                    'otherrule'                => '',
                    'subqtext'                 => '',
                    'subq_svg'                 => '',
                    'feedback'                 => '',
                    'correctfeedback'          => '',
                    'partiallycorrectfeedback' => '',
                    'incorrectfeedback'        => '',
                    'scriptparams_q'           => '',
                    'scriptparams_fb'          => '',
                    'imgid_q'                  => 'circuit' . $idx . 'a',
                    'imgid_fb'                 => 'circuit' . $idx . 'b'
                ];
            }

            $answers = $qnode->answers;
            $idxAns = 0;
            foreach ($answers as $ans) {
                if ($idxAns >= $MAX_PARTS) break;
                $p = &$parts[$idxAns];
                $p['use'] = true;

                $getText = function($node, $name) {
                    if (!isset($node->$name)) return '';
                    $sub = $node->$name;
                    if (isset($sub->text)) return (string)$sub->text;
                    return (string)$sub;
                };

                $p['partindex']   = $getText($ans, 'partindex');
                $p['placeholder'] = $getText($ans, 'placeholder');
                $p['answermark']  = $getText($ans, 'answermark');
                $p['answertype']  = $getText($ans, 'answertype');
                $p['numbox']      = $getText($ans, 'numbox');
                $p['vars1']       = $getText($ans, 'vars1');
                $p['answer']      = $getText($ans, 'answer');
                $p['vars2']       = $getText($ans, 'vars2');
                $p['correctness'] = $getText($ans, 'correctness');
                $p['unitpenalty'] = $getText($ans, 'unitpenalty');
                $p['postunit']    = $getText($ans, 'postunit');
                $p['ruleid']      = $getText($ans, 'ruleid');
                $p['otherrule']   = $getText($ans, 'otherrule');

                $raw_subq = '';
                if (isset($ans->subqtext)) {
                    $raw_subq = $getText($ans, 'subqtext');
                }
                $p['feedback']                 = $getText($ans, 'feedback');
                $p['correctfeedback']          = $getText($ans, 'correctfeedback');
                $p['partiallycorrectfeedback'] = $getText($ans, 'partiallycorrectfeedback');
                $p['incorrectfeedback']        = $getText($ans, 'incorrectfeedback');

                if ($raw_subq !== '') {
                    if (preg_match('#<table[^>]*>.*?<tr[^>]*>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>.*?</tr>.*?</table>#is', $raw_subq, $mt)) {
                        $p['subq_svg'] = trim($mt[1]);
                        $p['subqtext'] = trim($mt[2]);
                    } else {
                        $p['subq_svg'] = '';
                        $p['subqtext'] = $raw_subq;
                    }
                }

                $idxAns++;
            }

            $js_raw_only = $js_source;
            if ($js_raw_only !== '' && preg_match('#<script[^>]*>([\s\S]*?)</script>#i', $js_raw_only, $ms)) {
                $js_raw_only = $ms[1];
            }

            if ($js_raw_only !== '') {
                $config_main = extract_circuit_snippet($js_raw_only, 'circuit1');
                for ($i = 0; $i < $MAX_PARTS; $i++) {
                    $idx = $i + 1;
                    $parts[$i]['scriptparams_q']  = extract_circuit_snippet($js_raw_only, "circuit{$idx}a");
                    $parts[$i]['scriptparams_fb'] = extract_circuit_snippet($js_raw_only, "circuit{$idx}b");
                }
            }

            $svg_main_id = 'circuit1';
            if ($svg_source !== '' && preg_match('#<svg[^>]*\bid="([^"]+)"#i', $svg_source, $mId)) {
                $svg_main_id = $mId[1];
            }
        }
    }
    libxml_clear_errors();

} elseif ($action === 'load_script' && isset($_FILES['jsfile']) && is_uploaded_file($_FILES['jsfile']['tmp_name'])) {

    $js_source = file_get_contents($_FILES['jsfile']['tmp_name']);
    $js_raw_only = $js_source;
    if ($js_raw_only !== '' && preg_match('#<script[^>]*>([\s\S]*?)</script>#i', $js_raw_only, $ms2)) {
        $js_raw_only = $ms2[1];
    }
    $config_main = extract_circuit_snippet($js_raw_only, 'circuit1');
    for ($i = 0; $i < $MAX_PARTS; $i++) {
        $idx = $i + 1;
        $parts[$i]['scriptparams_q']  = extract_circuit_snippet($js_raw_only, "circuit{$idx}a");
        $parts[$i]['scriptparams_fb'] = extract_circuit_snippet($js_raw_only, "circuit{$idx}b");
        if ($parts[$i]['imgid_q']  === '') $parts[$i]['imgid_q']  = 'circuit' . $idx . 'a';
        if ($parts[$i]['imgid_fb'] === '') $parts[$i]['imgid_fb'] = 'circuit' . $idx . 'b';
    }
    if ($svg_main_id === '') $svg_main_id = 'circuit1';

} elseif ($action === 'load_svg' && isset($_FILES['svgfile']) && is_uploaded_file($_FILES['svgfile']['tmp_name'])) {

    $svg_source = file_get_contents($_FILES['svgfile']['tmp_name']);
    if ($svg_main_id === '') {
        if (preg_match('#<svg[^>]*\bid="([^"]+)"#i', $svg_source, $mId)) {
            $svg_main_id = $mId[1];
        } else {
            $svg_main_id = 'circuit1';
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'generate' || $action === 'download_xml')) {

    $svg_trim = trim($svg_source);
    $js_trim  = trim($js_source);
    if ($svg_main_id === '') $svg_main_id = 'circuit1';

    // Reconstruction CIRCUIT_CONFIG complet
    $config_entries = [];

    if (trim($config_main) !== '') {
        $entry = build_config_entry($svg_main_id, $config_main);
        if ($entry !== '') $config_entries[] = $entry;
    }

    for ($i = 0; $i < $MAX_PARTS; $i++) {
        if (!$parts[$i]['use']) continue;
        $idx   = $i + 1;
        $cfg_q = trim($parts[$i]['scriptparams_q']);
        $cfg_fb= trim($parts[$i]['scriptparams_fb']);

        $id_q  = $parts[$i]['imgid_q']  !== '' ? $parts[$i]['imgid_q']  : ('circuit' . $idx . 'a');
        $id_fb = $parts[$i]['imgid_fb'] !== '' ? $parts[$i]['imgid_fb'] : ('circuit' . $idx . 'b');

        if ($cfg_q !== '') {
            $entry = build_config_entry($id_q, $cfg_q);
            if ($entry !== '') $config_entries[] = $entry;
        }
        if ($cfg_fb !== '') {
            $entry = build_config_entry($id_fb, $cfg_fb);
            if ($entry !== '') $config_entries[] = $entry;
        }
    }

    $config_block = '';
    if (!empty($config_entries)) {
        $config_block = "// ==========================\n"
                      . "// 1) CONFIG PAR IMAGE\n"
                      . "// ==========================\n"
                      . "var CIRCUIT_CONFIG = {\n"
                      . implode(",\n", $config_entries) . "\n"
                      . "};\n";
    }

    if ($config_block !== '') {
        $js_raw_only    = $js_trim;
        $has_script_tag = false;
        if ($js_raw_only !== '' && preg_match('#<script[^>]*>([\s\S]*?)</script>#i', $js_raw_only, $ms)) {
            $js_raw_only    = $ms[1];
            $has_script_tag = true;
        }

        if (preg_match(
            '#// ==========================\\s*// 1\\) CONFIG PAR IMAGE[\\s\\S]*?var\\s+CIRCUIT_CONFIG\\s*=\\s*\\{[\\s\\S]*?\\};#',
            $js_raw_only
        )) {
            $js_raw_only = preg_replace(
                '#// ==========================\\s*// 1\\) CONFIG PAR IMAGE[\\s\\S]*?var\\s+CIRCUIT_CONFIG\\s*=\\s*\\{[\\s\\S]*?\\};#',
                $config_block,
                $js_raw_only
            );
        } else {
            if ($js_raw_only !== '') {
                $js_raw_only .= "\n\n" . $config_block;
            } else {
                $js_raw_only = $config_block;
            }
        }

        if ($has_script_tag) {
            $js_trim = "<script>\n" . $js_raw_only . "\n</script>";
        } else {
            $js_trim = $js_raw_only;
        }
    }

    if ($js_trim !== '' && stripos($js_trim, '<script') === false) {
        $js_block = "<script>\n" . $js_trim . "\n</script>";
    } else {
        $js_block = $js_trim;
    }

    $body = $intro_html;

    // Remplacement fiable du placeholder par le SVG
    if ($svg_trim !== '') {
        if (preg_match('/<!--\s*IMAGE\s*1\s*-->/i', $body)) {
            $body = preg_replace('/<!--\s*IMAGE\s*1\s*-->/i', $svg_trim, $body, 1);
        } elseif (strpos($body, '[SVG_1]') !== false) {
            $body = str_replace('[SVG_1]', $svg_trim, $body);
        } elseif (preg_match('/<!--\s*SVG_1\s*-->/i', $body)) {
            $body = preg_replace('/<!--\s*SVG_1\s*-->/i', $svg_trim, $body, 1);
        } else {
            $body = $svg_trim . "\n" . $body;
        }
    }

    if ($js_block !== '') {
        $body .= "\n" . $js_block;
    }

    $questiontext_html = $body;

    $qname_safe = $qname !== '' ? $qname : 'Question_SVG_Formula';

    $varsrandom_xml = htmlspecialchars($varsrandom, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
    $varsglobal_xml = htmlspecialchars($varsglobal, ENT_NOQUOTES | ENT_XML1, 'UTF-8');

    $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<quiz>\n";
    $xml .= "  <question type=\"formulas\">\n";
    $xml .= "    <name>\n";
    $xml .= "      <text>" . htmlspecialchars($qname_safe, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</text>\n";
    $xml .= "    </name>\n";
    $xml .= "    <questiontext format=\"html\">\n";
    $xml .= "      <text><![CDATA[" . $questiontext_html . "]]></text>\n";
    $xml .= "    </questiontext>\n";
    $xml .= "    <generalfeedback format=\"html\"><text></text></generalfeedback>\n";
    $xml .= "    <defaultgrade>5</defaultgrade>\n";
    $xml .= "    <penalty>0</penalty>\n";
    $xml .= "    <hidden>0</hidden>\n";
    $xml .= "    <idnumber></idnumber>\n";
    $xml .= "    <correctfeedback format=\"html\"><text>Votre réponse est correcte.</text></correctfeedback>\n";
    $xml .= "    <partiallycorrectfeedback format=\"html\"><text>Votre réponse est partiellement correcte.</text></partiallycorrectfeedback>\n";
    $xml .= "    <incorrectfeedback format=\"html\"><text>Votre réponse est incorrecte.</text></incorrectfeedback>\n";
    $xml .= "    <shownumcorrect/>\n";
    $xml .= "    <varsrandom><text>" . $varsrandom_xml . "</text></varsrandom>\n";
    $xml .= "    <varsglobal><text>" . $varsglobal_xml . "</text></varsglobal>\n";
    $xml .= "    <answernumbering><text>abc</text></answernumbering>\n";

    $enc = function($s) {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
    };

for ($i = 0; $i < $MAX_PARTS; $i++) {
    $p = $parts[$i];
    if (!$p['use']) continue;
    if (trim($p['answer']) === '' && trim($p['subqtext']) === '' && trim($p['incorrectfeedback']) === '') {
        continue;
    }

    $xml .= "    <answers>\n";
    $xml .= "     <partindex><text>"   . $enc($p['partindex'])   . "</text></partindex>\n";
    $xml .= "     <placeholder><text>" . $enc($p['placeholder']) . "</text></placeholder>\n";
    $xml .= "     <answermark><text>"  . $enc($p['answermark'])  . "</text></answermark>\n";
    $xml .= "     <answertype><text>"  . $enc($p['answertype'])  . "</text></answertype>\n";
    $xml .= "     <numbox><text>"      . $enc($p['numbox'])      . "</text></numbox>\n";
    $xml .= "     <vars1><text>"       . $enc($p['vars1'])       . "</text></vars1>\n";
    $xml .= "     <answer><text>"      . $enc($p['answer'])      . "</text></answer>\n";
    $xml .= "     <vars2><text>"       . $enc($p['vars2'])       . "</text></vars2>\n";
    $xml .= "     <correctness><text><![CDATA[" . $p['correctness'] . "]]></text></correctness>\n";
    $xml .= "     <unitpenalty><text>" . $enc($p['unitpenalty']) . "</text></unitpenalty>\n";
    $xml .= "     <postunit><text>"    . $enc($p['postunit'])    . "</text></postunit>\n";
    $xml .= "     <ruleid><text>"      . $enc($p['ruleid'])      . "</text></ruleid>\n";
    $xml .= "     <otherrule><text>"   . $enc($p['otherrule'])   . "</text></otherrule>\n";

    // ====== 1) Question : tableau SVG + texte ======
    $subq_html = '';
    if ($p['subq_svg'] !== '' || $p['subqtext'] !== '') {
        // tableau à 2 colonnes : SVG / texte
        $subq_html = '<table style="width:100%"><tr>'
                   . '<td style="width:50%;vertical-align:top;">' . $p['subq_svg'] . '</td>'
                   . '<td style="width:50%;vertical-align:top;">' . $p['subqtext'] . '</td>'
                   . '</tr></table>';
    } else {
        $subq_html = $p['subqtext'];
    }

    $xml .= "     <subqtext format=\"html\"><text><![CDATA[" . $subq_html . "]]></text></subqtext>\n";

    // ====== 2) Feedbacks texte (neutres) inchangés ======
    $xml .= "     <feedback format=\"html\"><text><![CDATA[" . $p['feedback'] . "]]></text></feedback>\n";
    $xml .= "     <correctfeedback format=\"html\"><text><![CDATA[" . $p['correctfeedback'] . "]]></text></correctfeedback>\n";
    $xml .= "     <partiallycorrectfeedback format=\"html\"><text><![CDATA[" . $p['partiallycorrectfeedback'] . "]]></text></partiallycorrectfeedback>\n";

    // ====== 3) Feedback incorrect : restaurer un tableau complet ======
    $incorrect_raw = $p['incorrectfeedback'];

    // Cas 1 : le XML d'origine contenait déjà un tableau complet -> on le garde tel quel
    if (strpos($incorrect_raw, '<td') !== false || strpos($incorrect_raw, '<table') !== false) {
        $incorrect_html = $incorrect_raw;
    } else {
        // Cas 2 : on ne manipule que le texte de la cellule de droite dans l'éditeur
        // -> on reconstruit un tableau 2 colonnes (SVG de la question + texte feedback)
        $svg_for_fb = $p['subq_svg']; // on réutilise le même SVG que pour la question

        if ($svg_for_fb !== '' || $incorrect_raw !== '') {
            $incorrect_html = '<table style="width:100%"><tr>'
                            . '<td style="width:50%;vertical-align:top;">' . $svg_for_fb . '</td>'
                            . '<td style="width:50%;vertical-align:top;">' . $incorrect_raw . '</td>'
                            . '</tr></table>';
        } else {
            // fallback : rien de spécial, juste le texte brut
            $incorrect_html = $incorrect_raw;
        }
    }

    $xml .= "     <incorrectfeedback format=\"html\"><text><![CDATA[" . $incorrect_html . "]]></text></incorrectfeedback>\n";
    $xml .= "    </answers>\n";
}


    $xml .= "  </question>\n";
    $xml .= "</quiz>\n";

    $xml_skeleton = $xml;
}

// Si on est en mode téléchargement XML, on sort immédiatement le fichier
if ($action === 'download_xml' && $xml_skeleton !== '') {
    $fnameBase = $qname !== '' ? $qname : 'Question_SVG_Formula';
    $fnameBase = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $fnameBase);
    $fname     = $fnameBase . '.xml';

    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo $xml_skeleton;
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Éditeur Formula + SVG + script global (V18)</title>
<style>
    body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        margin: 0;
        padding: 0;
        background: #111;
        color: #eee;
    }
    header {
        padding: 10px 20px;
        background: #222;
        border-bottom: 1px solid #444;
    }
    h1 {
        font-size: 1.1rem;
        margin: 0;
    }
    main {
        padding: 15px 20px 40px;
    }
    .section {
        margin-top: 20px;
        padding: 12px;
        border-radius: 6px;
        background: #181818;
        border: 1px solid #333;
    }
    .section h2 {
        font-size: 1rem;
        margin: 0 0 8px;
    }
    label {
        display: block;
        font-weight: 600;
        margin: 8px 0 4px;
    }
    input[type="text"], input[type="number"] {
        width: 100%;
        padding: 6px 8px;
        border-radius: 4px;
        border: 1px solid #555;
        background: #181818;
        color: #eee;
    }
    textarea {
        width: 100%;
        min-height: 100px;
        padding: 6px 8px;
        border-radius: 4px;
        border: 1px solid #555;
        background: #121212;
        color: #eee;
        font-family: monospace;
        font-size: 0.85rem;
        resize: vertical;
    }
    .textarea-small {
        min-height: 70px;
    }
    button {
        padding: 6px 12px;
        border-radius: 4px;
        border: none;
        background: #3a86ff;
        color: #fff;
        font-weight: 600;
        cursor: pointer;
        margin-top: 6px;
    }
    button:hover { background: #295fba; }
    .btn-secondary {
        background: #555;
    }
    .btn-secondary:hover {
        background: #777;
    }
    .help {
        font-size: 0.8rem;
        color: #aaa;
        margin-top: 4px;
    }
    .row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
    }
    .col {
        flex: 1 1 250px;
        min-width: 220px;
    }
    .preview {
        margin-top: 8px;
        padding: 8px;
        background: #fff;
        color: #000;
        border-radius: 4px;
        max-height: 260px;
        overflow: auto;
    }
    .wysi-toolbar button {
        margin-right: 4px;
        margin-bottom: 4px;
    }
    #questiontext_editor {
        width: 100%;
        min-height: 160px;
        padding: 8px;
        border-radius: 4px;
        border: 1px solid #555;
        background: #111;
        color: #eee;
        font-size: 0.9rem;
        overflow: auto;
    }
    .mini {
        font-size: 0.75rem;
        color: #aaa;
    }
    .part-card {
        margin-top: 12px;
        padding: 10px;
        border-radius: 6px;
        border: 1px solid #333;
        background: #151515;
    }
    .part-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
        font-weight: 600;
    }
    .part-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0,1fr));
        grid-template-rows: auto auto;
        gap: 8px;
    }
    .cell {
        border: 1px solid #333;
        border-radius: 4px;
        padding: 6px;
        background: #101010;
        font-size: 0.85rem;
    }
    .cell h4 {
        margin: 0 0 4px;
        font-size: 0.85rem;
    }
    .cell .preview-html {
        margin-top: 4px;
        background: #fff;
        color: #000;
        max-height: 180px;
        overflow: auto;
        border-radius: 4px;
        padding: 4px;
    }
    .csv-area {
        min-height: 80px;
    }
</style>

<script>
window.MathJax = {
  tex: { inlineMath: [['\\(','\\)'], ['$', '$']] },
  svg: { fontCache: 'global' }
};
</script>
<script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js"></script>
</head>
<body>
<header>
    <h1>Éditeur Formula + SVG + script global — V18</h1>
</header>
<main>
<form method="post" enctype="multipart/form-data" id="mainForm">

    <div class="section">
        <h2>1. Import / Export</h2>
        <div class="row">
            <div class="col">
                <label for="qname">Nom de la question (Q1)</label>
                <input type="text" id="qname" name="qname"
                       value="<?php echo htmlspecialchars($qname, ENT_QUOTES, 'UTF-8'); ?>">

                <label for="xmlfile">Importer XML Moodle (formulas)</label>
                <input type="file" id="xmlfile" name="xmlfile" accept=".xml">
                <button type="submit" name="action" value="load_xml" class="btn-secondary">
                    Charger XML
                </button>
            </div>

            <div class="col">
                <label for="jsfile">Importer script global (circuits.js)</label>
                <input type="file" id="jsfile" name="jsfile" accept=".js,.txt">
                <button type="submit" name="action" value="load_script" class="btn-secondary">
                    Charger script
                </button>

                <label for="svgfile" style="margin-top:8px;">Importer SVG principal</label>
                <input type="file" id="svgfile" name="svgfile" accept=".svg">
                <button type="submit" name="action" value="load_svg" class="btn-secondary">
                    Charger SVG
                </button>
            </div>

            <div class="col">
                <label>CSV (config en cours)</label>
                <textarea id="csv_data" class="csv-area"
                          placeholder="key;value&#10;qname;&quot;Ohm_3RMV2&quot;&#10;..."></textarea>
                <button type="button" class="btn-secondary" onclick="syncQuestionEditor();exportCSV();">
                    Exporter CSV (zone texte)
                </button>
                <button type="button" class="btn-secondary" onclick="downloadCSVFile();">
                    Exporter CSV (fichier)
                </button>
            </div>
        </div>

        <div style="margin-top:10px;">
            <button type="submit" name="action" value="generate">
                Générer / voir XML
            </button>
            <button type="submit" name="action" value="download_xml" class="btn-secondary">
                Exporter XML (fichier)
            </button>
            <span class="mini">Le XML complet s’affiche en bas (bouton “Générer”).</span>
        </div>
    </div>

    <div class="section">
        <h2>2. Variables (Formula)</h2>
        <div class="row">
            <div class="col">
                <label for="varsrandom">Variables aléatoires (varsrandom)</label>
                <textarea id="varsrandom" name="varsrandom" class="textarea-small"><?php
                    echo htmlspecialchars($varsrandom, ENT_NOQUOTES, 'UTF-8');
                ?></textarea>
            </div>
            <div class="col">
                <label for="varsglobal">Variables globales (varsglobal)</label>
                <textarea id="varsglobal" name="varsglobal" class="textarea-small"
                    placeholder="Val_R_1=VarR1;&#10;Val_R_2=VarR2; ..."><?php
                    echo htmlspecialchars($varsglobal, ENT_NOQUOTES, 'UTF-8');
                ?></textarea>
            </div>
            <div class="col">
                <button type="button" class="btn-secondary" onclick="listVariables();">
                    Liste des {variables}
                </button>
                <div id="varlist" class="preview" style="background:#111;color:#eee;">
                    (Clique sur “Liste des {variables}”.)
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3. Texte principal (WYSIWYG + SVG principal / script)</h2>
        <div class="row">
            <div class="col">
                <div class="wysi-toolbar">
                    <button type="button" class="btn-secondary" onclick="qt_wrapSelection('<b>','</b>');"><b>B</b></button>
                    <button type="button" class="btn-secondary" onclick="qt_wrapSelection('<i>','</i>');"><i>I</i></button>
                    <button type="button" class="btn-secondary" onclick="qt_insertLatexInline();">LaTeX inline</button>
                    <button type="button" class="btn-secondary" onclick="qt_insertLatexBlock();">LaTeX bloc</button>
                    <button type="button" class="btn-secondary" onclick="qt_insertSvgTag();">Insérer [SVG_1]</button>
                </div>
                <div id="questiontext_editor" contenteditable="true"></div>
                <input type="hidden" id="intro_html" name="intro_html"
                       value="<?php echo htmlspecialchars($intro_html, ENT_NOQUOTES, 'UTF-8'); ?>">
                <div class="help">
                    Tag <code>[SVG_1]</code> ou commentaire <code>&lt;!-- IMAGE 1 --&gt;</code> = place du SVG principal.  
                    Le script global sera injecté une seule fois.
                </div>
            </div>

            <div class="col">
                <label for="svg_source">SVG principal</label>
                <textarea id="svg_source" name="svg_source"><?php
                    echo htmlspecialchars($svg_source, ENT_NOQUOTES, 'UTF-8');
                ?></textarea>

                <label for="svg_main_id">ID du SVG principal (clé dans CIRCUIT_CONFIG)</label>
                <input type="text" id="svg_main_id" name="svg_main_id"
                       value="<?php echo htmlspecialchars($svg_main_id ?: 'circuit1', ENT_QUOTES, 'UTF-8'); ?>">

                <div class="preview" id="svg_main_preview"></div>

                <label for="config_main">Config image principale (<?php echo htmlspecialchars($svg_main_id ?: 'circuit1', ENT_QUOTES, 'UTF-8'); ?>)</label>
                <textarea id="config_main" name="config_main" class="textarea-small"><?php
                    echo htmlspecialchars($config_main, ENT_NOQUOTES, 'UTF-8');
                ?></textarea>
            </div>

            <div class="col">
                <label for="js_source">Script global (circuits.js)</label>
                <textarea id="js_source" name="js_source" class="textarea-small"><?php
                    echo htmlspecialchars($js_source, ENT_NOQUOTES, 'UTF-8');
                ?></textarea>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3 bis. Parties / sous-questions</h2>
        <div class="help">
            Question – SVG / rendu : rendu dynamique de l’image (id = champ “ID image / config”).<br>
            Feedback – SVG / rendu : idem avec l’id de feedback.
        </div>

        <div style="margin:6px 0 10px;">
            <button type="button" class="btn-secondary" onclick="refreshAllPreviews();">
                Refresh tous les previews
            </button>
        </div>

        <?php for ($i = 0; $i < $MAX_PARTS; $i++): ?>
            <div class="part-card" id="part_<?php echo $i; ?>">
                <div class="part-header">
                    <span>Partie <?php echo $i; ?> (partindex = <?php echo htmlspecialchars($parts[$i]['partindex'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                    <label style="font-weight:400;">
                        <input type="checkbox" name="use_part_<?php echo $i; ?>" <?php echo $parts[$i]['use'] ? 'checked' : ''; ?>>
                        Utiliser cette partie
                    </label>
                </div>

                <div class="row">
                    <div class="col">
                        <label for="answer_<?php echo $i; ?>">Answer (Formula)</label>
                        <textarea id="answer_<?php echo $i; ?>" name="answer_<?php echo $i; ?>" class="textarea-small"><?php
                            echo htmlspecialchars($parts[$i]['answer'], ENT_NOQUOTES, 'UTF-8');
                        ?></textarea>
                    </div>
                </div>

                <details style="margin-top:4px;">
                    <summary>Paramètres Formula (réduits)</summary>
                    <div class="row">
                        <div class="col">
                            <label for="numbox_<?php echo $i; ?>">Numbox</label>
                            <input type="text" id="numbox_<?php echo $i; ?>" name="numbox_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['numbox'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="correctness_<?php echo $i; ?>" style="margin-top:6px;">Correctness</label>
                            <input type="text" id="correctness_<?php echo $i; ?>" name="correctness_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['correctness'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col">
                            <label for="placeholder_<?php echo $i; ?>">Placeholder</label>
                            <input type="text" id="placeholder_<?php echo $i; ?>" name="placeholder_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['placeholder'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="answermark_<?php echo $i; ?>">Answermark</label>
                            <input type="text" id="answermark_<?php echo $i; ?>" name="answermark_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['answermark'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col">
                            <label for="answertype_<?php echo $i; ?>">Answertype</label>
                            <input type="text" id="answertype_<?php echo $i; ?>" name="answertype_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['answertype'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="vars1_<?php echo $i; ?>">vars1</label>
                            <input type="text" id="vars1_<?php echo $i; ?>" name="vars1_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['vars1'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </details>

                <div class="part-grid" style="margin-top:8px;">
                    <!-- Ligne 1 -->
                    <div class="cell">
                        <h4>Question – SVG / rendu</h4>
                        <label for="imgid_q_<?php echo $i; ?>" class="mini">
                            ID image / config (ex. <code>circuit<?php echo $i+1; ?>a</code>)
                        </label>
                        <input type="text" id="imgid_q_<?php echo $i; ?>" name="imgid_q_<?php echo $i; ?>"
                               value="<?php echo htmlspecialchars($parts[$i]['imgid_q'], ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" class="btn-secondary mini" onclick="updateSvgPreview(<?php echo $i; ?>);">
                            Refresh SVG
                        </button>
                        <div id="subq_preview_svg_<?php echo $i; ?>" class="preview-html"></div>
                    </div>

                    <div class="cell">
                        <h4>Question – Param script</h4>
                        <textarea id="scriptparams_q_<?php echo $i; ?>" name="scriptparams_q_<?php echo $i; ?>" class="textarea-small"><?php
                            echo htmlspecialchars($parts[$i]['scriptparams_q'], ENT_NOQUOTES, 'UTF-8');
                        ?></textarea>
                        <div class="help">
                            Bloc utilisé pour <code>CIRCUIT_CONFIG["<?php echo htmlspecialchars($parts[$i]['imgid_q'], ENT_QUOTES, 'UTF-8'); ?>"]</code>.
                        </div>
                    </div>

                    <div class="cell">
                        <h4>Question – HTML + preview</h4>
                        <textarea id="subqtext_<?php echo $i; ?>" name="subqtext_<?php echo $i; ?>" class="textarea-small"><?php
                            echo htmlspecialchars($parts[$i]['subqtext'], ENT_NOQUOTES, 'UTF-8');
                        ?></textarea>
                        <button type="button" class="btn-secondary mini" onclick="openVarModal('subqtext_<?php echo $i; ?>');">
                            LaTeX helper
                        </button>
                        <button type="button" class="btn-secondary mini" onclick="updateSubqPreview(<?php echo $i; ?>);">
                            Refresh preview
                        </button>
                        <div id="subq_preview_<?php echo $i; ?>" class="preview-html"></div>
                    </div>

                    <!-- Ligne 2 -->
                    <div class="cell">
                        <h4>Feedback – SVG / rendu</h4>
                        <label for="imgid_fb_<?php echo $i; ?>" class="mini">
                            ID image / config feedback (ex. <code>circuit<?php echo $i+1; ?>b</code>)
                        </label>
                        <input type="text" id="imgid_fb_<?php echo $i; ?>" name="imgid_fb_<?php echo $i; ?>"
                               value="<?php echo htmlspecialchars($parts[$i]['imgid_fb'], ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" class="btn-secondary mini" onclick="updateSvgFeedbackPreview(<?php echo $i; ?>);">
                            Refresh SVG
                        </button>
                        <div id="fb_preview_svg_<?php echo $i; ?>" class="preview-html"></div>
                    </div>

                    <div class="cell">
                        <h4>Feedback – Param script</h4>
                        <textarea id="scriptparams_fb_<?php echo $i; ?>" name="scriptparams_fb_<?php echo $i; ?>" class="textarea-small"><?php
                            echo htmlspecialchars($parts[$i]['scriptparams_fb'], ENT_NOQUOTES, 'UTF-8');
                        ?></textarea>
                    </div>

                    <div class="cell">
                        <h4>Feedback – HTML + preview</h4>
                        <textarea id="incorrectfeedback_<?php echo $i; ?>" name="incorrectfeedback_<?php echo $i; ?>" class="textarea-small"><?php
                            echo htmlspecialchars($parts[$i]['incorrectfeedback'], ENT_NOQUOTES, 'UTF-8');
                        ?></textarea>
                        <button type="button" class="btn-secondary mini" onclick="openVarModal('incorrectfeedback_<?php echo $i; ?>');">
                            LaTeX helper
                        </button>
                        <button type="button" class="btn-secondary mini" onclick="updateFbPreview(<?php echo $i; ?>);">
                            Refresh preview
                        </button>
                        <div id="fb_preview_<?php echo $i; ?>" class="preview-html"></div>
                    </div>
                </div>

                <details style="margin-top:8px;">
                    <summary>Feedbacks neutres + paramètres avancés</summary>
                    <label for="feedback_<?php echo $i; ?>">Feedback (neutre)</label>
                    <textarea id="feedback_<?php echo $i; ?>" name="feedback_<?php echo $i; ?>" class="textarea-small"><?php
                        echo htmlspecialchars($parts[$i]['feedback'], ENT_NOQUOTES, 'UTF-8');
                    ?></textarea>

                    <label for="correctfeedback_<?php echo $i; ?>">Correctfeedback</label>
                    <textarea id="correctfeedback_<?php echo $i; ?>" name="correctfeedback_<?php echo $i; ?>" class="textarea-small"><?php
                        echo htmlspecialchars($parts[$i]['correctfeedback'], ENT_NOQUOTES, 'UTF-8');
                    ?></textarea>

                    <label for="partiallycorrectfeedback_<?php echo $i; ?>">Partiallycorrectfeedback</label>
                    <textarea id="partiallycorrectfeedback_<?php echo $i; ?>" name="partiallycorrectfeedback_<?php echo $i; ?>" class="textarea-small"><?php
                        echo htmlspecialchars($parts[$i]['partiallycorrectfeedback'], ENT_NOQUOTES, 'UTF-8');
                    ?></textarea>

                    <div class="row">
                        <div class="col">
                            <label for="vars2_<?php echo $i; ?>">vars2</label>
                            <input type="text" id="vars2_<?php echo $i; ?>" name="vars2_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['vars2'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="unitpenalty_<?php echo $i; ?>">unitpenalty</label>
                            <input type="text" id="unitpenalty_<?php echo $i; ?>" name="unitpenalty_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['unitpenalty'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col">
                            <label for="postunit_<?php echo $i; ?>">postunit</label>
                            <input type="text" id="postunit_<?php echo $i; ?>" name="postunit_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['postunit'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="ruleid_<?php echo $i; ?>">ruleid</label>
                            <input type="text" id="ruleid_<?php echo $i; ?>" name="ruleid_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['ruleid'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col">
                            <label for="otherrule_<?php echo $i; ?>">otherrule</label>
                            <input type="text" id="otherrule_<?php echo $i; ?>" name="otherrule_<?php echo $i; ?>"
                                   value="<?php echo htmlspecialchars($parts[$i]['otherrule'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <input type="hidden" name="partindex_<?php echo $i; ?>" value="<?php
                        echo htmlspecialchars($parts[$i]['partindex'], ENT_QUOTES, 'UTF-8');
                    ?>">
                </details>

                <input type="hidden" id="subq_svg_<?php echo $i; ?>" name="subq_svg_<?php echo $i; ?>"
                       value="<?php echo htmlspecialchars($parts[$i]['subq_svg'], ENT_NOQUOTES, 'UTF-8'); ?>">
            </div>
        <?php endfor; ?>

        <div style="margin-top:10px;">
            <button type="button" class="btn-secondary" onclick="addPart();">
                Ajouter une partie
            </button>
        </div>
    </div>

    <?php if ($questiontext_html !== ''): ?>
        <div class="section">
            <h2>Prévisualisation du questiontext (Q1)</h2>
            <div class="preview">
                <?php echo $questiontext_html; ?>
            </div>
        </div>

        <div class="section">
            <h2>XML complet (à importer dans Moodle)</h2>
            <textarea rows="20"><?php
                echo htmlspecialchars($xml_skeleton, ENT_NOQUOTES, 'UTF-8');
            ?></textarea>
        </div>
    <?php endif; ?>

    <div class="section">
        <h2>Aide LaTeX rapide</h2>
        <div class="help">
            Inline <code>\( ... \)</code>, bloc <code>$$ ... $$</code>, Ω = <code>\Omega</code>, etc.
        </div>
    </div>

    <div class="section">
        <h2>Log debug JS</h2>
        <div id="debuglog"
             style="background:#000;color:#0f0;font-family:monospace;font-size:0.8rem;
                    max-height:200px;overflow:auto;padding:4px;border-radius:4px;">
            (log vide)
        </div>
        <button type="button" class="btn-secondary" onclick="clearLog();">
            Clear log
        </button>
    </div>

    <!-- Popup variables -->
    <div id="varModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9998;"></div>
    <div id="varModal" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
        background:#1e1e1e;border:1px solid #444;border-radius:6px;padding:10px 12px;z-index:9999;min-width:260px;max-width:420px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <strong>Variables disponibles</strong>
            <button type="button" class="btn-secondary" onclick="closeVarModal();" style="margin-top:0;">Fermer</button>
        </div>
        <div class="help" style="margin-bottom:6px;">
            Clique pour insérer la variable (format <code>{Nom}</code>) dans la zone de texte ciblée.
        </div>
        <div id="varModalList" style="max-height:260px;overflow:auto;"></div>
    </div>

</form>
<script>
// ====== Constante globale ======
var MAX_PARTS = <?php echo $MAX_PARTS; ?>;

// ====== LOG DEBUG GLOBAL ======
function logDebug(msg) {
    var box = document.getElementById('debuglog');
    if (!box) return;
    // si premier message = "(log vide)", on efface
    if (box.childNodes.length === 1 && box.textContent.trim() === '(log vide)') {
        box.innerHTML = '';
    }
    var line = document.createElement('div');
    var now  = new Date();
    var t    = now.toTimeString().slice(0, 8);
    line.textContent = '[' + t + '] ' + msg;
    box.appendChild(line);
    box.scrollTop = box.scrollHeight;
}
function clearLog() {
    var box = document.getElementById('debuglog');
    if (box) box.innerHTML = '(log vide)';
}
window.onerror = function (message, source, lineno, colno, error) {
    logDebug('JS ERROR: ' + message + ' @ ' + lineno + ':' + colno);
};

// ==== WYSIWYG principal (intro HTML) ====
function qt_getEditor() { 
    return document.getElementById('questiontext_editor'); 
}
function qt_wrapSelection(before, after) {
    var ed = qt_getEditor();
    if (!ed) return;
    ed.focus();
    var sel = window.getSelection();
    var txt = sel ? String(sel) : '';
    document.execCommand('insertHTML', false, before + txt + after);
}
function qt_insertLatexInline() {
    var ed = qt_getEditor(); 
    if (!ed) return;
    ed.focus();
    document.execCommand('insertHTML', false, '\\(  \\)');
}
function qt_insertLatexBlock() {
    var ed = qt_getEditor(); 
    if (!ed) return;
    ed.focus();
    document.execCommand('insertHTML', false, '$$  $$');
}
function qt_insertSvgTag() {
    var ed = qt_getEditor(); 
    if (!ed) return;
    ed.focus();
    document.execCommand('insertHTML', false, '[SVG_1]');
}
function syncQuestionEditor() {
    var ed = qt_getEditor();
    var hidden = document.getElementById('intro_html');
    if (ed && hidden) hidden.value = ed.innerHTML;
}

// ==== Variables depuis varsglobal (partie gauche uniquement) ====
function getVarNamesFromVarGlobal() {
    logDebug('getVarNamesFromVarGlobal()');
    var vg = document.getElementById('varsglobal');
    if (!vg) {
        logDebug('getVarNamesFromVarGlobal: varsglobal introuvable');
        return [];
    }
    var lines = vg.value.split(/\r?\n/);
    var arr = [];
    var seen = {};
    for (var i = 0; i < lines.length; i++) {
        var line = lines[i].trim();
        if (!line) continue;
        var cmtIdx = line.indexOf('//');
        if (cmtIdx !== -1) line = line.substring(0, cmtIdx).trim();
        if (!line) continue;
        var eqIdx = line.indexOf('=');
        if (eqIdx === -1) continue;
        var left = line.substring(0, eqIdx).trim();
        if (!left) continue;
        left = left.replace(/;+$/g, '').trim();
        if (!left) continue;
        if (!seen[left]) {
            seen[left] = true;
            arr.push(left);
        }
    }
    arr.sort();
    logDebug('getVarNamesFromVarGlobal: ' + arr.length + ' var(s) trouvée(s)');
    return arr;
}
function listVariables() {
    logDebug('listVariables() appelé');
    var vars = getVarNamesFromVarGlobal();
    var box = document.getElementById('varlist');
    if (!box) {
        logDebug('listVariables: varlist introuvable');
        return;
    }
    if (!vars.length) {
        box.textContent = "Aucune variable détectée dans varsglobal.";
        logDebug('listVariables: aucune variable');
        return;
    }
    box.textContent = vars.join('\n');
    logDebug('listVariables: ' + vars.join(', '));
}

// ==== Popup insertion variables dans les textarea LaTeX ====
var currentLatexTarget = null;

function openVarModal(textareaId) {
    logDebug('openVarModal(' + textareaId + ')');
    currentLatexTarget = textareaId;
    var vars = getVarNamesFromVarGlobal();
    var listDiv = document.getElementById('varModalList');
    var modal = document.getElementById('varModal');
    var overlay = document.getElementById('varModalOverlay');
    if (!listDiv || !modal || !overlay) {
        logDebug('openVarModal: éléments modal manquants');
        return;
    }
    listDiv.innerHTML = '';
    if (!vars.length) {
        listDiv.textContent = "Aucune variable trouvée dans varsglobal.";
    } else {
        for (var i = 0; i < vars.length; i++) {
            var name = vars[i];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-secondary';
            btn.style.display = 'block';
            btn.style.width = '100%';
            btn.style.textAlign = 'left';
            btn.style.marginTop = '4px';
            btn.textContent = name;
            btn.onclick = (function(nm) {
                return function() { insertVarFromModal(nm); };
            })(name);
            listDiv.appendChild(btn);
        }
    }
    overlay.style.display = 'block';
    modal.style.display = 'block';
}
function closeVarModal() {
    var modal = document.getElementById('varModal');
    var overlay = document.getElementById('varModalOverlay');
    if (modal) modal.style.display = 'none';
    if (overlay) overlay.style.display = 'none';
    currentLatexTarget = null;
}
function insertVarFromModal(name) {
    logDebug('insertVarFromModal(' + name + ')');
    if (!currentLatexTarget) return;
    var el = document.getElementById(currentLatexTarget);
    if (!el) return;
    var snippet = '{' + name + '}';
    insertAtCursor(el, snippet);
    el.focus();
}
function insertAtCursor(el, text) {
    var start = (typeof el.selectionStart === 'number') ? el.selectionStart : el.value.length;
    var end   = (typeof el.selectionEnd   === 'number') ? el.selectionEnd   : el.value.length;
    var val   = el.value;
    el.value = val.slice(0, start) + text + val.slice(end);
    var pos = start + text.length;
    el.selectionStart = el.selectionEnd = pos;
}

// ==== CSV key;value ====
function csvEscape(v) {
    return '"' + v.replace(/"/g, '""') + '"';
}
function exportCSV() {
    logDebug('exportCSV()');
    var fields = {};
    syncQuestionEditor();
    fields.qname       = document.getElementById('qname').value || '';
    fields.intro_html  = document.getElementById('intro_html').value || '';
    fields.svg_source  = document.getElementById('svg_source').value || '';
    fields.js_source   = document.getElementById('js_source').value || '';
    fields.varsrandom  = document.getElementById('varsrandom').value || '';
    fields.varsglobal  = document.getElementById('varsglobal').value || '';
    fields.config_main = document.getElementById('config_main').value || '';

    for (var i = 0; i < MAX_PARTS; i++) {
        var keys = [
            'use_part_', 'partindex_', 'placeholder_', 'answermark_', 'answertype_',
            'numbox_', 'vars1_', 'answer_', 'vars2_', 'correctness_',
            'unitpenalty_', 'postunit_', 'ruleid_', 'otherrule_',
            'subqtext_', 'feedback_', 'correctfeedback_',
            'partiallycorrectfeedback_', 'incorrectfeedback_',
            'scriptparams_q_', 'scriptparams_fb_', 'subq_svg_'
        ];
        for (var k = 0; k < keys.length; k++) {
            var prefix = keys[k];
            var id = prefix + i;
            if (prefix === 'use_part_') {
                var chk = document.querySelector('input[name="' + id + '"]');
                fields[id] = (chk && chk.checked) ? '1' : '0';
            } else {
                var el = document.getElementById(id);
                if (el) fields[id] = el.value || '';
            }
        }
    }

    var lines = ['key;value'];
    for (var key in fields) {
        if (!fields.hasOwnProperty(key)) continue;
        lines.push(key + ';' + csvEscape(fields[key]));
    }
    document.getElementById('csv_data').value = lines.join('\n');
}
function parseCSVKeyValue(text) {
    var lines = text.split(/\r?\n/);
    lines = lines.filter(function(l){ return l.trim() !== ''; });
    if (lines.length < 2) return {};
    var map = {};
    for (var i = 1; i < lines.length; i++) {
        var line = lines[i];
        var idx = line.indexOf(';');
        if (idx === -1) continue;
        var key = line.substring(0, idx).trim();
        var val = line.substring(idx + 1).trim();
        if (val.charAt(0) === '"' && val.charAt(val.length-1) === '"') {
            val = val.substring(1, val.length-1);
        }
        val = val.replace(/""/g, '"');
        map[key] = val;
    }
    return map;
}
function importCSV() {
    logDebug('importCSV()');
    var text = document.getElementById('csv_data').value || '';
    var map = parseCSVKeyValue(text);
    if (!Object.keys || !Object.keys(map).length) {
        alert("CSV vide ou invalide.");
        logDebug('importCSV: CSV invalide');
        return;
    }
    if (map.qname       !== undefined) document.getElementById('qname').value       = map.qname;
    if (map.intro_html  !== undefined) {
        document.getElementById('intro_html').value = map.intro_html;
        qt_getEditor().innerHTML = map.intro_html;
    }
    if (map.svg_source  !== undefined) document.getElementById('svg_source').value  = map.svg_source;
    if (map.js_source   !== undefined) document.getElementById('js_source').value   = map.js_source;
    if (map.varsrandom  !== undefined) document.getElementById('varsrandom').value  = map.varsrandom;
    if (map.varsglobal  !== undefined) document.getElementById('varsglobal').value  = map.varsglobal;
    if (map.config_main !== undefined) document.getElementById('config_main').value = map.config_main;

    for (var i = 0; i < MAX_PARTS; i++) {
        var keys = [
            'use_part_', 'partindex_', 'placeholder_', 'answermark_', 'answertype_',
            'numbox_', 'vars1_', 'answer_', 'vars2_', 'correctness_',
            'unitpenalty_', 'postunit_', 'ruleid_', 'otherrule_',
            'subqtext_', 'feedback_', 'correctfeedback_',
            'partiallycorrectfeedback_', 'incorrectfeedback_',
            'scriptparams_q_', 'scriptparams_fb_', 'subq_svg_'
        ];
        for (var k = 0; k < keys.length; k++) {
            var prefix = keys[k];
            var id = prefix + i;
            if (map[id] !== undefined) {
                if (prefix === 'use_part_') {
                    var chk = document.querySelector('input[name="use_part_' + i + '"]');
                    if (chk) chk.checked = (map[id] === '1');
                } else {
                    var el = document.getElementById(id);
                    if (el) el.value = map[id];
                }
            }
        }
        updateSubqPreview(i);
        updateFbPreview(i);
        updateSvgPreview(i);
        updateSvgFeedbackPreview(i);
    }
    alert("Champs mis à jour depuis le CSV.");
    logDebug('importCSV: champs mis à jour');
}

// ==== Helpers preview & LaTeX ====

// déséchappe le contenu « &lt;td&gt;...&lt;/td&gt; » des textarea
function decodeHtmlEntities(str) {
    if (!str) return '';
    var txt = document.createElement('textarea');
    txt.innerHTML = str;
    return txt.value;
}

function extractRightTdContent(raw) {
    logDebug('extractRightTdContent()');
    if (!raw) return '';
    try {
        var decoded = decodeHtmlEntities(raw);

        var parser = new DOMParser();
        var doc = parser.parseFromString(decoded, 'text/html');
        var tds = doc.getElementsByTagName('td');
        logDebug('extractRightTdContent: nb <td> = ' + tds.length);

        if (tds.length >= 2) return tds[1].innerHTML;
        if (tds.length === 1) return tds[0].innerHTML;

        return decoded;
    } catch (e) {
        logDebug('extractRightTdContent ERROR: ' + e);
        return raw;
    }
}

function updateSubqPreview(i) {
    logDebug('updateSubqPreview(' + i + ')');
    var txt = document.getElementById('subqtext_' + i);
    var div = document.getElementById('subq_preview_' + i);
    if (!txt || !div) {
        logDebug('updateSubqPreview: éléments manquants pour i=' + i);
        return;
    }
    var html = txt.value || '';
    html = extractRightTdContent(html);
    div.innerHTML = html;
    if (window.MathJax && MathJax.typesetPromise) {
        MathJax.typesetPromise([div])["catch"](function(err){
            logDebug('MathJax subq error: ' + err);
        });
    }
}

function updateFbPreview(i) {
    logDebug('updateFbPreview(' + i + ')');
    var txt = document.getElementById('incorrectfeedback_' + i);
    var div = document.getElementById('fb_preview_' + i);
    if (!txt || !div) {
        logDebug('updateFbPreview: éléments manquants pour i=' + i);
        return;
    }

    var full = txt.value || '';
    logDebug('updateFbPreview: longueur texte=' + full.length);

    var html = extractRightTdContent(full);
    logDebug('updateFbPreview: extrait = ' + html.substring(0, 80).replace(/\s+/g,' '));

    div.innerHTML = html;

    if (window.MathJax && MathJax.typesetPromise) {
        MathJax.typesetPromise([div])["catch"](function(err){
            logDebug('MathJax fb error: ' + err);
        });
    }
}

// ==== UTIL : base SVG et instances par partie ====
function getBaseSvgText() {
    var ta = document.getElementById('svg_source');
    return ta ? (ta.value || '') : '';
}

// fabrique un SVG avec un nouvel id pour la partie (circuit1a, circuit2a, ...)
function buildSvgInstanceForPart(partIndex) {
    var base = getBaseSvgText();
    if (!base) return '';

    // on détecte l'id du <svg> principal
    var m = base.match(/<svg[^>]*id="([^"]+)"/i);
    var baseId = m ? m[1] : 'circuit1';
    var newId  = 'circuit' + (partIndex + 1) + 'a';

    var search = 'id="' + baseId + '"';
    var idx = base.indexOf(search);
    if (idx === -1) {
        // on ne trouve pas, on renvoie tel quel
        return base;
    }
    return base.substring(0, idx) + 'id="' + newId + '"' + base.substring(idx + search.length);
}

// ==== Rendu SVG dans un iframe (principal + question + feedback) ====
function renderCircuitInIframe(containerId, svgInstance, jsSource, cfgSnippet, circuitId) {
    var container = document.getElementById(containerId);
    if (!container) {
        logDebug('renderCircuitInIframe: container ' + containerId + ' introuvable');
        return;
    }
    container.innerHTML = '';

    if (!svgInstance || !jsSource) {
        container.textContent = "SVG ou script global manquant.";
        logDebug('renderCircuitInIframe: SVG ou JS manquant');
        return;
    }

    var txtJs = jsSource;
    try {
        // on retire éventuellement un wrapper externe <scr...>
        var openTag  = '<scr' + 'ipt';
        var closeTag = '<' + '/scr' + 'ipt>';
        var idxOpen  = txtJs.indexOf(openTag);
        if (idxOpen !== -1) {
            var idxGt = txtJs.indexOf('>', idxOpen);
            if (idxGt !== -1) {
                txtJs = txtJs.substring(idxGt + 1);
            }
        }
        var idxClose = txtJs.lastIndexOf(closeTag);
        if (idxClose !== -1) {
            txtJs = txtJs.substring(0, idxClose);
        }
        logDebug('renderCircuitInIframe: wrapper external retiré');
    } catch (e) {
        logDebug('renderCircuitInIframe: strip wrapper ERROR ' + e);
    }

    // rendre CIRCUIT_CONFIG global si pattern trouvé
    txtJs = txtJs.replace(/var\s+CIRCUIT_CONFIG\s*=\s*\{/, 'window.CIRCUIT_CONFIG = {');
    logDebug('renderCircuitInIframe: CIRCUIT_CONFIG globalisé si pattern trouvé');

    // on ajoute la config dédiée pour ce circuit, si fournie
    if (cfgSnippet && circuitId) {
        function indentSnippetLoc(s) {
            var lines = s.split(/\r?\n/);
            for (var n = 0; n < lines.length; n++) {
                lines[n] = '        ' + lines[n];
            }
            return lines.join('\n');
        }
        var override =
            '(function(){\n' +
            '  if (typeof window.CIRCUIT_CONFIG !== "object" || !window.CIRCUIT_CONFIG) {\n' +
            '    window.CIRCUIT_CONFIG = {};\n' +
            '  }\n' +
            '  window.CIRCUIT_CONFIG["' + circuitId + '"] = {\n' +
                 indentSnippetLoc(cfgSnippet) + '\n' +
            '  };\n' +
            '})();\n';
        txtJs += '\n' + override;
    }

    // neutraliser les séquences de fermeture HTML dans le JS injecté
    var safeJs = txtJs.replace(/<\//g, '<\\/');

    var iframe = document.createElement('iframe');
    iframe.setAttribute('sandbox', 'allow-scripts');
    iframe.style.width = '100%';
    iframe.style.height = '220px';

    var head = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
    var foot = '</body></html>';
    var oTag = '<scr' + 'ipt>';
    var cTag = '<' + '/scr' + 'ipt>';
    var html = head + svgInstance + oTag + safeJs + cTag + foot;

    iframe.srcdoc = html;
    container.appendChild(iframe);

    logDebug('renderCircuitInIframe: contenu écrit dans iframe (' + circuitId + ')');
}

// ==== Preview principal SVG ====
function updateMainSvgPreview() {
    var svgSrc  = (document.getElementById('svg_source').value || '').trim();
    var jsSrc   = (document.getElementById('js_source').value || '').trim();
    var cfgMain = (document.getElementById('config_main').value || '').trim();
    var container = document.getElementById('svg_main_preview');
    if (!container) return;
    if (!svgSrc || !jsSrc) {
        container.textContent = "SVG ou script global manquant.";
        return;
    }
    renderCircuitInIframe('svg_main_preview', svgSrc, jsSrc, cfgMain, 'circuit1');
}

// ==== Preview dynamique SVG pour chaque partie (circuitNa) ====
function updateSvgPreview(i) {
    var svgSrc  = (document.getElementById('svg_source').value || '').trim();
    var jsSrc   = (document.getElementById('js_source').value || '').trim();
    var cfgMain = (document.getElementById('config_main').value || '').trim();
    var cfgPart = (document.getElementById('scriptparams_q_' + i).value || '').trim();

    logDebug('updateSvgPreview(' + i + '): svg length=' + svgSrc.length +
             ', js length=' + jsSrc.length +
             ', cfgMain length=' + cfgMain.length +
             ', cfgPart length=' + cfgPart.length);

    if (!svgSrc || !jsSrc) {
        var container = document.getElementById('subq_preview_svg_' + i);
        if (container) container.textContent = "SVG ou script global manquant.";
        return;
    }

    var idx = i + 1;
    var circuitId = 'circuit' + idx + 'a';

    // on fabrique une instance dédiée avec un id différent
    var svgInstance = buildSvgInstanceForPart(i);
    var effectiveCfg = cfgPart || cfgMain;

    renderCircuitInIframe('subq_preview_svg_' + i, svgInstance, jsSrc, effectiveCfg, circuitId);
}

// ==== Preview SVG de feedback (circuitNb) ====
function updateSvgFeedbackPreview(i) {
    var svgSrc  = (document.getElementById('svg_source').value || '').trim();
    var jsSrc   = (document.getElementById('js_source').value || '').trim();
    var cfgFb   = (document.getElementById('scriptparams_fb_' + i).value || '').trim();

    logDebug('updateSvgFeedbackPreview(' + i + '): svg length=' + svgSrc.length +
             ', js length=' + jsSrc.length +
             ', cfgFb length=' + cfgFb.length);

    if (!svgSrc || !jsSrc || !cfgFb) {
        var container = document.getElementById('fb_preview_svg_' + i);
        if (container) container.textContent = "SVG ou script feedback manquant.";
        return;
    }

    var idx = i + 1;
    var circuitId = 'circuit' + idx + 'b';

    // même logique : instance dédiée par partie
    var svgInstance = buildSvgInstanceForPart(i);

    renderCircuitInIframe('fb_preview_svg_' + i, svgInstance, jsSrc, cfgFb, circuitId);
}

// ==== Refresh global (tous les SVG + texte) ====
function refreshAllPreviews() {
    updateMainSvgPreview();
    for (var i = 0; i < MAX_PARTS; i++) {
        updateSubqPreview(i);
        updateFbPreview(i);
        updateSvgPreview(i);
        updateSvgFeedbackPreview(i);
    }
}

// ==== Ajout de partie ====
function addPart() {
    for (var i = 0; i < MAX_PARTS; i++) {
        var chk = document.querySelector('input[name="use_part_' + i + '"]');
        if (chk && !chk.checked) {
            chk.checked = true;
            var card = document.getElementById('part_' + i);
            if (card && card.scrollIntoView) {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return;
        }
    }
    alert("Toutes les parties sont déjà actives.");
}

// ==== Download helpers XML / CSV ====
function downloadTextFile(filename, mimeType, content) {
    var blob = new Blob([content], { type: mimeType });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function downloadXml() {
    var ta = document.getElementById('xml_output');
    if (!ta) {
        alert("Zone XML introuvable.");
        return;
    }
    var txt = ta.value || ta.textContent || '';
    if (!txt.trim()) {
        alert("Pas de XML à télécharger.");
        return;
    }
    downloadTextFile('question_formulas.xml', 'application/xml', txt);
}

function downloadCsv() {
    var ta = document.getElementById('csv_data');
    if (!ta) {
        alert("Zone CSV introuvable.");
        return;
    }
    var txt = ta.value || '';
    if (!txt.trim()) {
        alert("Pas de CSV à télécharger.");
        return;
    }
    downloadTextFile('questions_formulas.csv', 'text/csv', txt);
}

// ==== INIT ====
document.addEventListener('DOMContentLoaded', function() {
    logDebug('DOM fully loaded');
    var ed = qt_getEditor();
    var hidden = document.getElementById('intro_html');
    if (ed && hidden) ed.innerHTML = hidden.value || '';

    // SVG principal + toutes les parties
    updateMainSvgPreview();
    for (var i = 0; i < MAX_PARTS; i++) {
        updateSubqPreview(i);
        updateFbPreview(i);
        updateSvgPreview(i);
        updateSvgFeedbackPreview(i);
    }

    var form = document.getElementById('mainForm');
    if (form) {
        form.addEventListener('submit', function() {
            logDebug('submit mainForm');
            // on force les SVG de chaque partie avant envoi pour l'export XML
            for (var i = 0; i < MAX_PARTS; i++) {
                var hiddenSvg = document.getElementById('subq_svg_' + i);
                if (hiddenSvg) {
                    hiddenSvg.value = buildSvgInstanceForPart(i);
                }
            }
            syncQuestionEditor();
        });
    }
});
</script>
</html>

</main>
</body>
</html>
