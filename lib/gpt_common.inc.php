<?php

/**
 * Loads api key from range config depending on courseware range
 *
 * @return string|null OpenAI API Key
 */
function getApiKey(\Courseware\Block $block): ?string
{
    $range_id = $block->container->getStructuralElement()->range_id;
    return RangeConfig::get($range_id)->getValue('COURSEWARE_GPT_API_KEY');
}

/**
 * Stores openai api key in range config depending on courseware range
 *
 * @param string $api_key openai api key
 */
function storeApiKey(\Courseware\Block $block, string $api_key)
{
    $range_id = $block->container->getStructuralElement()->range_id;
    RangeConfig::get($range_id)->store('COURSEWARE_GPT_API_KEY', $api_key);
}

/**
 * Strips html tags from the passed summary and formats the summary.
 *
 * @param string $summary
 * @return string Striped and formatted summary
 */
function formatSummary(string $summary): string {
    // Add new lines after tags: <br>, </p>, </h*>, </div>, </li>, </section>, </article>, </blockquote>, </details>, </summary>
    $summary = preg_replace([
        '/<br.*?>/i',
        '/<\\/p.*?>/i',
        '/<\\/h[1-6].*?>/i',
        '/<\\/div.*?>/i',
        '/<\\/li.*?>/i',
        '/<\\/section.*?>/i',
        '/<\\/article.*?>/i',
        '/<\\/blockquote.*?>/i',
        '/<\\/details.*?>/i',
        '/<\\/summary.*?>/i',
    ], "$0\n" , $summary);

    // Remove html tags
    $summary = strip_tags($summary);

    return trim($summary);
}

/**
 * Creates a summary from all text blocks of a structural element
 *
 * Supported block types: 'headline', 'document', 'dialog-cards', 'key-point', 'code', 'typewriter', 'text', 'biography-goals'
 *
 * @param \Courseware\Block $site_block block of a courseware site
 * @return string summary of a courseware site
 */
function getCoursewareSummary(\Courseware\Block $site_block) {
    $structural_element = $site_block->container->getStructuralElement();

    $summary = '';

    // Add site title
    $summary .= $structural_element->title . "\n\n";

    // PDF parser
    $pdf_parser = new \Smalot\PdfParser\Parser();

    // Iterate over all containers
    foreach ($structural_element->containers as $container) {
        foreach ($container->blocks as $block) {
            $payload = json_decode($block->payload, true);
            $block_summary = '';
            switch ($block->getBlockType()) {
                case \Courseware\BlockTypes\Text::getType():
                    $block_summary .= $payload['text'] ?? '';
                    break;
                case \Courseware\BlockTypes\Code::getType():
                    if (isset($payload['content'])) {
                        $lang = $payload['lang'] ?? "";
                        $block_summary .= "{$lang} code:\n";
                        $block_summary .= $payload['content'];
                    }
                    break;
                case \Courseware\BlockTypes\Headline::getType():
                    $block_summary .= $payload['title'] . "\n" . $payload['subtitle'] ?? '';
                    break;
                case \Courseware\BlockTypes\KeyPoint::getType():
                    $block_summary .= $payload['text'] ?? '';
                    break;
                case \Courseware\BlockTypes\DialogCards::getType():
                    if (isset($payload['cards']) && is_array($payload['cards'])) {
                        foreach ($payload['cards'] as $card) {
                            $block_summary .= $card['front_text'] . "\n" . $card['back_text'] ?? '';
                        }
                    }
                    break;
                case \Courseware\BlockTypes\Typewriter::getType():
                    $block_summary .= $payload['text'] ?? '';
                    break;
                //case \Courseware\BlockTypes\BiographyGoals::getType():
                //    $block_summary .= $payload['type'] . "\n" . $payload['description'] ?? '';
                //    break;
                case \Courseware\BlockTypes\Document::getType():
                    if (isset($payload['doc_type']) && $payload['doc_type'] == 'pdf') {
                        if (!$payload['file_id']) {
                            break;
                        }

                        if ($fileRef = \FileRef::find($payload['file_id'])) {
                            $block_summary .= $pdf_parser->parseFile($fileRef->file->getPath())->getText();
                        }
                    }
                    break;
            }

            // Remove whitespace and html tags
            $block_summary = formatSummary($block_summary);
            if (!empty($block_summary)) {
                $summary .= $block_summary . "\n\n";
            }
        }
    }

    return trim($summary);
}
