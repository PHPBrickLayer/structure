<?php

namespace BrickLayer\Lay\Libs\String;

use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use DOMDocument;

final class WordCount
{
    private int $words_per_minute = 265;
    private int $secs_allocated_to_img = 6;
    private int $secs_allocated_to_video = 3;
    private int $secs_allocated_to_audio = 2;
    private int $extra_secs = 0;

    public function wpm(int $words_per_minute) : static {
        $this->words_per_minute = $words_per_minute;
        return $this;
    }

    public function extra_secs(int $extra_secs) : static {
        $this->extra_secs = $extra_secs;
        return $this;
    }

    public function img_allocation(int $secs_allocated_to_img) : static {
        $this->secs_allocated_to_img = $secs_allocated_to_img;
        return $this;
    }

    public function audio_allocation(int $secs_allocated_to_audio) : static {
        $this->secs_allocated_to_audio = $secs_allocated_to_audio;
        return $this;
    }

    public function video_allocation(int $secs_allocated_to_video) : static {
        $this->secs_allocated_to_video = $secs_allocated_to_video;
        return $this;
    }

    use IsSingleton;

    /**
     * @param string $words
     *
     * @return (DOMDocument|float|int)[]
     *
     * @psalm-return array{dom: DOMDocument, total: int<1, max>, duration: float}
     */
    public function text(string $words, string $encoding = "UTF-8") : array {
        $dom = (new DOMDocument('1.0', $encoding));

        @$dom->loadHTML('<?xml encoding="' . $encoding . '">' . $words, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $words = explode(" ", trim(strip_tags($words)));
        $words = count($words);

        $img_count = $dom->getElementsByTagName("img")->count() * $this->secs_allocated_to_img;
        $video_count = $dom->getElementsByTagName("video")->count() * $this->secs_allocated_to_video;
        $audio_count = $dom->getElementsByTagName("audio")->count() * $this->secs_allocated_to_audio;

        $duration = $words + $img_count + $video_count + $audio_count + $this->extra_secs;

        foreach ($dom->childNodes as $item) {
            if ($item->nodeType == XML_PI_NODE)
                $dom->removeChild($item);
        }
        $dom->encoding = $encoding; // reset original encoding

        return [
            "dom" => $dom,
            "total" => $words,
            "duration" => ceil($duration/$this->words_per_minute)
        ];
    }
}