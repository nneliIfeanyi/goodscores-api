<?php

namespace App\Services;

use App\Models\School;
use App\Models\Header;
use App\Models\User;

/**
 * Builds exam paper HTML for browser printing.
 */
class PdfService
{
    public static function buildHtml(array $paper, array $user): string
    {
        $school = null;
        if (!empty($user['school_id'])) {
            $school = School::findById((int)$user['school_id']);
        }

        $personalHeader = $school ? null : Header::findDefault((int)($user['id'] ?? 0));

        $settings = $paper['paper_settings']
            ?? ($school ? json_decode($school['paper_settings'] ?? '{}', true) : [])
            ?? [];
        $schoolSettings = $school ? (json_decode($school['paper_settings'] ?? '{}', true) ?: []) : [];
        $userSettings = json_decode($user['pdf_settings'] ?? '{}', true) ?: [];
        $preferenceKeys = [
          'paper_format', 'paper_size', 'orientation', 'margin_top', 'margin_bottom', 'margin_left', 'margin_right',
          'header_height', 'show_logo', 'show_school_name', 'footer_text', 'show_marks',
          'pdf_font_size', 'pdf_font_family',
        ];
        foreach ($preferenceKeys as $key) {
          if (array_key_exists($key, $userSettings)) {
            $settings[$key] = $userSettings[$key];
          }
        }
        foreach ($preferenceKeys as $key) {
          if (array_key_exists($key, $schoolSettings)) {
            $settings[$key] = $schoolSettings[$key];
          }
        }
        $showMarks = array_key_exists('show_marks', $settings)
          ? (bool)$settings['show_marks']
          : (bool)($user['pdf_show_marks'] ?? true);

        $schoolName = $school['name']
          ?? ($personalHeader['school_name'] ?? 'Examination Centre');
        $extra = $school['address']
          ?? ($personalHeader['extra_line'] ?? '');
        $logoPath = null;
        $logo = $school['logo'] ?? ($personalHeader['logo_path'] ?? null);
        $showLogo = (bool)($settings['show_logo'] ?? true);
        $showSchoolName = (bool)($settings['show_school_name'] ?? true);
        if ($showLogo && !empty($logo)) {
          $candidate = __DIR__ . '/../../storage/' . ltrim($logo, '/');
            if (is_file($candidate)) {
                $logoPath = $candidate;
            }
        }

        $title = htmlspecialchars($paper['title'] ?? 'Exam Paper');
        $session = htmlspecialchars($_ENV['CURRENT_SESSION'] ?? '');
        $subject = htmlspecialchars($paper['subject_name'] ?? '');
        $className = htmlspecialchars($paper['class_name'] ?? '');
        $termName = htmlspecialchars($paper['term_name'] ?? '');
        $totalMarks = $paper['total_marks'] ?? '';
        $timeAllowed = htmlspecialchars($settings['time_allowed'] ?? '________');
        $schoolNameEsc = htmlspecialchars($schoolName);
        $extraEsc = htmlspecialchars($extra);

        $logoHtml = '';
        if ($logoPath) {
          $publicBase = rtrim($_ENV['APP_URL'] ?? '/goodscores/backend/public', '/');
          $relativeLogo = ltrim($logo, '/');
          $browserLogo = $publicBase . '/storage/' . str_replace('%2F', '/', rawurlencode($relativeLogo));
          $logoHtml = '<img src="' . htmlspecialchars($browserLogo) . '" data-local-src="' . htmlspecialchars($logoPath) . '" style="display:block;max-height:55px;max-width:90px;margin:auto;" alt="Logo" />';
        }

        $questionsHtml = '';
        $renderedPassages = [];
        $questionsById = [];
        foreach ($paper['questions'] ?? [] as $question) {
          $questionsById[(int)$question['id']] = $question;
        }

        $sections = $settings['sections'] ?? [];
        if (!is_array($sections) || !$sections) {
          $sections = [[
            'key' => 'A',
            'title' => 'Questions',
            'instructions' => '',
            'question_ids' => array_keys($questionsById),
          ]];
        }
        $questionLayout = [];
        foreach ($settings['question_layout'] ?? [] as $layoutItem) {
          if (isset($layoutItem['question_id'])) {
            $questionLayout[(int)$layoutItem['question_id']] = $layoutItem;
          }
        }

        foreach ($sections as $section) {
          $sectionTitle = htmlspecialchars($section['title'] ?? 'Questions');
          $sectionInstructions = htmlspecialchars($section['instructions'] ?? '');
          $questionsHtml .= '<div class="paper-section">'
            . '<h2>' . $sectionTitle . '</h2>'
            . ($sectionInstructions !== '' ? '<div class="section-instructions">' . nl2br($sectionInstructions) . '</div>' : '');

          if (!empty($section['passage']['id'])) {
            $sectionPassageId = (int)$section['passage']['id'];
            if (!isset($renderedPassages[$sectionPassageId])) {
              $passageTitle = htmlspecialchars($section['passage']['title'] ?? 'Passage');
              $passageBody = strip_tags($section['passage']['body'] ?? '', '<p><br><strong><b><em><i><u><ol><ul><li>');
              $questionsHtml .= '<div style="margin:18px 0 10px;padding:10px 12px;border:1px solid #bbb;background:#fafafa;page-break-inside:avoid;">'
                . '<strong>' . $passageTitle . '</strong><div style="margin-top:6px;">' . $passageBody . '</div></div>';
              $renderedPassages[$sectionPassageId] = true;
            }
          }

          $sectionQuestionBlocks = [];

          $n = 1;
          $mainNumber = 0;
          $partNumber = 0;
          $subPartNumber = 0;
          $currentMain = 0;
          $currentPart = 0;
          $sectionQuestionIds = array_values($section['question_ids'] ?? []);
          foreach ($sectionQuestionIds as $sectionIndex => $questionId) {
            $q = $questionsById[(int)$questionId] ?? null;
            if (!$q) continue;
          $layout = (($q['type'] ?? '') === 'theory') ? ($questionLayout[(int)$questionId] ?? []) : [];
          $questionLevel = max(0, min(2, (int)($layout['level'] ?? 0)));
          if ($questionLevel === 0) {
            $mainNumber++;
            $currentMain = $mainNumber;
            $nextQuestionId = $sectionQuestionIds[$sectionIndex + 1] ?? null;
            $nextQuestion = $nextQuestionId ? ($questionsById[(int)$nextQuestionId] ?? null) : null;
            $nextLayout = ($nextQuestion && ($nextQuestion['type'] ?? '') === 'theory')
              ? ($questionLayout[(int)$nextQuestionId] ?? [])
              : [];
            $hasParts = ((int)($nextLayout['level'] ?? 0)) > 0;
            $partNumber = $hasParts ? 1 : 0;
            $subPartNumber = 0;
            $currentPart = $hasParts ? 1 : 0;
            $questionLabel = $hasParts ? $currentMain . '(a)' : (string)$currentMain;
          } elseif ($questionLevel === 1) {
            if (!$currentMain) $currentMain = ++$mainNumber;
            $partNumber++;
            $currentPart = $partNumber;
            $subPartNumber = 0;
            $questionLabel = $currentMain . '(' . chr(96 + $currentPart) . ')';
          } else {
            if (!$currentMain) $currentMain = ++$mainNumber;
            if (!$currentPart) $currentPart = ++$partNumber;
            $subPartNumber++;
            $roman = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'][$subPartNumber - 1] ?? (string)$subPartNumber;
            $questionLabel = $currentMain . '(' . chr(96 + $currentPart) . ')(' . $roman . ')';
          }
          $questionIndent = $questionLevel * 18;
          $body = strip_tags($q['body'] ?? '', '<p><br><strong><b><em><i><u><ol><ul><li>');
          if (!empty($q['passage_id']) && !isset($renderedPassages[$q['passage_id']])) {
            $passageTitle = htmlspecialchars($q['passage_title'] ?? 'Comprehension passage');
            $passageBody = strip_tags($q['passage_body'] ?? '', '<p><br><strong><b><em><i><u><ol><ul><li>');
            $questionsHtml .= '<div style="margin:18px 0 10px;padding:10px 12px;border:1px solid #bbb;background:#fafafa;page-break-inside:avoid;">'
              . '<strong>' . $passageTitle . '</strong><div style="margin-top:6px;">' . $passageBody . '</div></div>';
            $renderedPassages[$q['passage_id']] = true;
          }
            $marks = $q['marks'] ?? 1;
            $type = strtoupper($q['type'] ?? '');

            $opts = '';
            if (($q['type'] ?? '') === 'mcq' && !empty($q['options'])) {
              $optionItems = [];
              foreach ($q['options'] as $o) {
                $key = htmlspecialchars($o['key'] ?? '');
                $text = htmlspecialchars($o['text'] ?? '');
                $optionItems[] = "<span class=\"inline-option\"><strong>{$key}.</strong> {$text}</span>";
              }
              if (($settings['paper_format'] ?? 'columns') === 'inline_options') {
                $opts = '<span class="inline-options">' . "\t" . implode('', $optionItems) . '</span>';
              } else {
                $opts = '<div style="margin:5px 0 0 18px;font-size:9pt;line-height:1.25;">';
                foreach ($optionItems as $optionItem) {
                  $opts .= '<div style="margin-bottom:3px;">' . $optionItem . '</div>';
                }
                $opts .= '</div>';
              }
            }

            $images = '';
            $diagramRequest = '';
            $diagramSpec = $q['diagram_spec'] ?? ($q['diagram_request'] ?? []);
            $diagramSvg = $diagramSpec['type'] === 'precise_diagram'
              ? ($diagramSpec['svg'] ?? '')
              : '';
            if (is_string($diagramSvg) && preg_match('/^<svg\b[\s\S]*<\/svg>$/i', trim($diagramSvg))) {
              $diagramRequest = '<div style="margin:12px auto;text-align:center;width:100%;">'
                . preg_replace('/<svg\b/i', '<svg style="display:block;max-width:400px;width:100%;height:auto;max-height:300px;margin:0 auto;"', $diagramSvg, 1)
                . '</div>';
            }
            foreach ($q['images'] ?? [] as $img) {
              $relativeImagePath = ltrim($img['file_path'] ?? '', '/');
              $path = __DIR__ . '/../../storage/' . $relativeImagePath;
              if (is_file($path)) {
                    $cap = htmlspecialchars($img['caption'] ?? '');
                $publicBase = rtrim($_ENV['APP_URL'] ?? '/goodscores/backend/public', '/');
                $browserSrc = $publicBase . '/storage/' . str_replace('%2F', '/', rawurlencode($relativeImagePath));
                    $images .= '<div style="margin:12px auto;text-align:center;width:100%;">'
                  . '<img src="' . htmlspecialchars($browserSrc) . '" data-local-src="' . htmlspecialchars($path) . '" style="display:block;max-width:400px;width:100%;height:auto;max-height:300px;object-fit:contain;margin:0 auto;" />'
                        . ($cap ? '<div style="font-size:10px;color:#555;">' . $cap . '</div>' : '')
                        . '</div>';
                }
            }

            $sectionQuestionBlocks[] = "
              <div style=\"margin-bottom:16px;margin-left:{$questionIndent}px;page-break-inside:avoid;\">
                <div style=\"font-size:12pt;\">
                  <strong>{$questionLabel}.</strong> {$body}
                  <span style=\"float:right;font-size:10pt;color:#444;\">[{$marks} mark" . ($marks == 1 ? '' : 's') . "]</span>
                </div>
                {$diagramRequest}
                {$images}
                {$opts}
              </div>";
            $n++;
          }
          $columnCount = count($sectionQuestionBlocks);
          $leftCount = (int)ceil($columnCount / 2);
          $leftBlocks = array_slice($sectionQuestionBlocks, 0, $leftCount);
          $rightBlocks = array_slice($sectionQuestionBlocks, $leftCount);
          if (($settings['paper_format'] ?? 'columns') === 'inline_options') {
            $questionsHtml .= '<table class="section-question-columns" width="100%"><tr>'
              . '<td class="question-column">' . implode('', $leftBlocks) . '</td>'
              . '<td class="question-column">' . implode('', $rightBlocks) . '</td>'
              . '</tr></table></div>';
          } else {
            $questionsHtml .= '<table class="section-question-columns" width="100%"><tr>'
              . '<td class="question-column">' . implode('', $leftBlocks) . '</td>'
              . '<td class="question-column">' . implode('', $rightBlocks) . '</td>'
              . '</tr></table></div>';
          }
        }

        if (!$showMarks) {
          $questionsHtml = preg_replace('/\s*<span style="float:right;font-size:10pt;color:#444;">.*?<\/span>/', '', $questionsHtml) ?: $questionsHtml;
        }

        $date = date('j F Y');
        $pdfFontSize = max(8, min(18, (float)($settings['pdf_font_size'] ?? ($user['pdf_font_size'] ?? 11))));
        $fontFamilies = [
          'dejavusans' => 'DejaVu Sans',
          'dejavuserif' => 'DejaVu Serif',
          'freesans' => 'FreeSans',
          'freeserif' => 'FreeSerif',
          'freemono' => 'FreeMono',
        ];
        $pdfFontKey = $settings['pdf_font_family'] ?? ($user['pdf_font_family'] ?? 'dejavusans');
        $pdfFontSize = isset($settings['pdf_font_size']) ? (float)$settings['pdf_font_size'] : $pdfFontSize;
        $pdfFontSize = max(8, min(18, $pdfFontSize));
        $pdfFontFamily = $fontFamilies[$pdfFontKey] ?? 'DejaVu Sans';
        $paperSize = in_array(strtoupper((string)($settings['paper_size'] ?? 'A4')), ['A4', 'LETTER', 'LEGAL'], true)
          ? strtoupper((string)$settings['paper_size']) : 'A4';
        $orientation = ($settings['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
        $pageMargins = sprintf(
          '%smm %smm %smm %smm',
          max(0, (float)($settings['margin_top'] ?? 10)),
          max(0, (float)($settings['margin_right'] ?? 8)),
          max(0, (float)($settings['margin_bottom'] ?? 10)),
          max(0, (float)($settings['margin_left'] ?? 8))
        );
        $headerHeight = max(0, min(300, (float)($settings['header_height'] ?? 80)));
        $footerValue = array_key_exists('footer_text', $settings) ? (string)$settings['footer_text'] : 'End of Paper';
        $footerText = htmlspecialchars($footerValue);
        $schoolNameHtml = $showSchoolName ? '<h1>' . $schoolNameEsc . '</h1>' : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  @page { size: {$paperSize} {$orientation}; margin: {$pageMargins}; }
  body { font-family: {$pdfFontFamily}, sans-serif; font-size: {$pdfFontSize}pt; color: #111; }
  .exam-header { width: 100%; height: {$headerHeight}px; border-bottom: 1px solid #0d9488; padding-bottom: 2px; margin-bottom: 4px; }
  .exam-header-logo { width: 18%; text-align: center; vertical-align: middle; }
  .exam-header-identity { width: 64%; text-align: center; vertical-align: middle; }
  .exam-header h1 { margin: 0 0 2px; font-size: 13pt; color: #0f766e; }
  .exam-header .meta { font-size: 9pt; color: #333; margin-top: 2px; }
  .info-row { margin: 4px 0 7px; font-size: 9pt; }
  .info-row td { padding: 1px 6px 1px 0; }
  .instructions { border: 1px solid #ccc; padding: 5px 8px; margin-bottom: 8px; font-size: 8.5pt; background: #fafafa; }
  .paper-section { width: 100%; margin: 18px 0 14px; }
  .paper-section:first-child { margin-top: 8px; }
  .paper-section h2 { text-align: center; font-size: 13pt; margin: 0 0 6px; text-transform: uppercase; }
  .section-instructions { text-align: center; font-style: italic; margin-bottom: 5px; }
  .section-question-columns { border-collapse: separate; border-spacing: 18px 0; margin: 0 -18px; }
  .question-column { width: 50%; vertical-align: top; }
  .inline-options { white-space: pre-wrap; tab-size: 4; }
  .inline-option { display: inline; margin-right: 1.5em; }
  .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 8pt; color: #888; border-top: 1px solid #ddd; padding-top: 4px; }
</style>
</head>
<body>
  <table class="exam-header">
    <tr>
      <td class="exam-header-logo">{$logoHtml}</td>
      <td class="exam-header-identity">
        {$schoolNameHtml}
        <div style="font-size:8.5pt;">{$extraEsc}</div>
      </td>
      <td class="exam-header-logo"></td>
    </tr>
  </table>

  <table class="info-row" width="100%">
    <tr>
      <td><strong>Subject:</strong> {$subject}</td>
      <td><strong>Class:</strong> {$className}</td>
      <td><strong>Term:</strong> {$termName}</td>
    </tr>
    <tr>
      <td><strong>Total marks:</strong> {$totalMarks}</td>
      <td><strong>Time allowed:</strong> {$timeAllowed}</td>
      <td><strong>Session:</strong> {$session}</td>
    </tr>
  </table>

  <div class="instructions">
    <strong>Instructions</strong>
    <ol style="margin:4px 0 0 16px;padding:0;">
      <li>Write your answers clearly in the spaces provided or in your answer booklet.</li>
      <li>For multiple-choice questions, choose the correct option.</li>
    </ol>
  </div>

  <div class="paper-content">
    {$questionsHtml}
  </div>

  <div class="footer">{$footerText}</div>
</body>
</html>
HTML;
    }

    /**
     * @return array{success:bool,path?:string,filename?:string,error?:string,html?:string}
     */
    public static function renderPdf(array $paper, array $user): array
    {
        $html = self::buildHtml($paper, $user);
        $filename = 'paper_' . ($paper['id'] ?? 'draft') . '_' . date('Ymd_His') . '.pdf';
        $outDir = __DIR__ . '/../../storage/exports';
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }
        $outPath = $outDir . '/' . $filename;

        // Save HTML for print-to-PDF so live hosting has no Composer dependency.
        $htmlName = str_replace('.pdf', '.html', $filename);
        $htmlPath = $outDir . '/' . $htmlName;
        $html = str_replace('</body>', '<script>window.addEventListener("load", function () { window.print(); });</script></body>', $html);
        file_put_contents($htmlPath, $html);

        return [
            'success'  => true,
            'path'     => $htmlPath,
            'filename' => $htmlName,
            'relative' => 'exports/' . $htmlName,
            'fallback' => true,
            'message'  => 'Printable HTML ready. Use the browser print dialog to save it as PDF.',
        ];
    }
}
