<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OgImageController extends Controller
{
    public function matchImage(Request $request)
    {
        $homeLogo  = $request->get('home_logo', '');
        $awayLogo  = $request->get('away_logo', '');
        $score     = $request->get('score', '? : ?');
        $homeName  = $request->get('home_name', '');
        $awayName  = $request->get('away_name', '');

        $cacheKey  = md5("{$homeLogo}_{$awayLogo}_{$score}_{$homeName}_{$awayName}");
        $cacheDir  = storage_path('app/public/og/matches');
        $cachePath = "{$cacheDir}/{$cacheKey}.png";

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        if (file_exists($cachePath)) {
            return response()->file($cachePath, [
                'Content-Type'  => 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        $img = $this->buildImage($homeLogo, $awayLogo, $score, $homeName, $awayName);

        imagepng($img, $cachePath, 8);
        imagedestroy($img);

        return response()->file($cachePath, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function generateAndSave(Post $post): ?string
    {
        if (!$post->fixture_id || !$post->fixture) {
            return null;
        }

        $fixture = $post->fixture->load(['homeTeam', 'awayTeam', 'score']);
        $homeLogo = $fixture->homeTeam->logo_url ?? '';
        $awayLogo = $fixture->awayTeam->logo_url ?? '';
        $isFinished = in_array($fixture->status_short, ['FT', 'AET', 'PEN']);
        $score = $isFinished
            ? ($fixture->score->goals_home ?? '?') . ' : ' . ($fixture->score->goals_away ?? '?')
            : '? : ?';
        $homeName = $fixture->homeTeam->name ?? '';
        $awayName = $fixture->awayTeam->name ?? '';

        $img = $this->buildImage($homeLogo, $awayLogo, $score, $homeName, $awayName);

        $dir = public_path('images/og/posts');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'post-' . $post->id . '-' . $fixture->id . '.png';
        $path = $dir . '/' . $filename;
        imagepng($img, $path);
        imagedestroy($img);

        return 'images/og/posts/' . $filename;
    }

    private function buildImage(string $homeLogo, string $awayLogo, string $score, string $homeName, string $awayName)
    {
        // ── Canvas ──────────────────────────────────────────────────────────
        $w = 1200;
        $h = 630;

        // Load background image (PNG despite .jpg extension)
        $bgPath = public_path('images/og/default.jpg');
        $bgSrc  = imagecreatefrompng($bgPath);
        $img    = imagescale($bgSrc, $w, $h);
        imagedestroy($bgSrc);

        // Semi-transparent dark overlay for readability (~60% opacity)
        $overlay = imagecreatetruecolor($w, $h);
        imagealphablending($overlay, false);
        $overlayColor = imagecolorallocatealpha($overlay, 0, 0, 0, 40);
        imagefill($overlay, 0, 0, $overlayColor);
        imagecopymerge($img, $overlay, 0, 0, 0, 0, $w, $h, 60);
        imagedestroy($overlay);

        // Colours
        $lime      = imagecolorallocate($img, 204, 255,   0);  // #CCFF00
        $white     = imagecolorallocate($img, 255, 255, 255);
        $gray      = imagecolorallocate($img, 120, 120, 120);
        $darkgray  = imagecolorallocate($img,  30,  30,  30);

        // ── Font setup ───────────────────────────────────────────────────────
        $fontPath = resource_path('fonts/Roboto-Black.ttf');
        if (!file_exists($fontPath)) {
            $fontDir = resource_path('fonts');
            if (!is_dir($fontDir)) mkdir($fontDir, 0755, true);
            @file_put_contents($fontPath, @file_get_contents(
                'https://github.com/google/fonts/raw/main/apache/roboto/static/Roboto-Black.ttf'
            ));
        }
        if (!file_exists($fontPath)) {
            $fontPath = null;
        }

        // ── Load logos ──────────────────────────────────────────────────────
        $logoSize = 160;
        $logoY    = 90;

        $this->drawLogo($img, $homeLogo, 120, $logoY, $logoSize);
        $this->drawLogo($img, $awayLogo, $w - 120 - $logoSize, $logoY, $logoSize);

        // ── Score ────────────────────────────────────────────────────────────
        if ($fontPath) {
            // ── TTF path ─────────────────────────────────────────────────────

            // Score — dominant element, 120px
            $fontSize  = 120;
            $scoreText = $score;
            $bbox      = imagettfbbox($fontSize, 0, $fontPath, $scoreText);
            $tw        = abs($bbox[4] - $bbox[0]);
            $th        = abs($bbox[5] - $bbox[1]);
            $tx        = (int)(($w - $tw) / 2);
            $ty        = (int)(($h / 2) + ($th / 2));
            imagettftext($img, $fontSize, 0, $tx, $ty, $lime, $fontPath, $scoreText);

            // Team names under logos — centred below each crest, adaptive font size
            $nameFontSize = 28;
            $maxNameWidth = 220;
            foreach ([
                [$homeName,  120 + $logoSize / 2],
                [$awayName,  $w - 120 - $logoSize / 2],
            ] as [$name, $cx]) {
                $name = mb_strtoupper($name);

                $fs = $nameFontSize;
                while ($fs >= 18) {
                    $nbbox = imagettfbbox($fs, 0, $fontPath, $name);
                    $nw    = abs($nbbox[4] - $nbbox[0]);
                    if ($nw <= $maxNameWidth) break;
                    $fs--;
                }

                $nbbox = imagettfbbox($fs, 0, $fontPath, $name);
                $nw    = abs($nbbox[4] - $nbbox[0]);
                if ($nw > $maxNameWidth) {
                    while (mb_strlen($name) > 1) {
                        $name = mb_substr($name, 0, mb_strlen($name) - 1);
                        $trial = $name . '...';
                        $nbbox = imagettfbbox($fs, 0, $fontPath, $trial);
                        $nw    = abs($nbbox[4] - $nbbox[0]);
                        if ($nw <= $maxNameWidth) { $name = $trial; break; }
                    }
                    $nbbox = imagettfbbox($fs, 0, $fontPath, $name);
                    $nw    = abs($nbbox[4] - $nbbox[0]);
                }

                $textX = (int)($cx - $nw / 2);
                $textX = max(10, $textX);

                imagettftext($img, $fs, 0,
                    $textX,
                    $logoY + $logoSize + 42,
                    $white, $fontPath, $name);
            }

            // VS label above score (for pre-match)
            if (str_contains($score, '?')) {
                $vs    = 'VS';
                $vsbbox = imagettfbbox(26, 0, $fontPath, $vs);
                $vsw   = abs($vsbbox[4] - $vsbbox[0]);
                imagettftext($img, 26, 0,
                    (int)(($w - $vsw) / 2),
                    (int)($h / 2) - 80,
                    $gray, $fontPath, $vs);
            }

            // Branding bottom-right (~22px)
            $brand = 'rezultati.net';
            $bbbox = imagettfbbox(22, 0, $fontPath, $brand);
            $bw    = abs($bbbox[4] - $bbbox[0]);
            imagettftext($img, 22, 0, $w - $bw - 30, $h - 22, $lime, $fontPath, $brand);

        } else {
            // ── Fallback: GD built-in fonts ──────────────────────────────────
            $scoreFont = 5;
            $charW = imagefontwidth($scoreFont);
            $charH = imagefontheight($scoreFont);
            $tw    = strlen($score) * $charW;
            $tx    = ($w - $tw) / 2;
            $ty    = $logoY + $logoSize / 2 - $charH / 2;
            imagestring($img, $scoreFont, (int)$tx, (int)$ty, $score, $lime);

            imagestring($img, 3, 120,                       $logoY + $logoSize + 10, mb_substr($homeName, 0, 15), $white);
            imagestring($img, 3, $w - 120 - $logoSize,      $logoY + $logoSize + 10, mb_substr($awayName, 0, 15), $white);
            imagestring($img, 2, $w - 120, $h - 20, 'rezultati.net', $lime);
        }

        // ── Top accent line ──────────────────────────────────────────────────
        imagesetthickness($img, 4);
        imageline($img, 0, 0, $w, 0, $lime);
        imagesetthickness($img, 1);

        return $img;
    }

    // ── Logo helper ─────────────────────────────────────────────────────────
    private function drawLogo($img, ?string $url, int $x, int $y, int $size): void
    {
        if (empty($url ?? "")) {
            $c = imagecolorallocate($img, 50, 50, 50);
            imagefilledellipse($img, $x + $size/2, $y + $size/2, $size, $size, $c);
            return;
        }

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $raw = @file_get_contents($url, false, $ctx);
            if (!$raw) throw new \Exception("Cannot fetch logo");

            $logo = @imagecreatefromstring($raw);
            if (!$logo) throw new \Exception("Cannot decode logo");

            $srcW = imagesx($logo);
            $srcH = imagesy($logo);
            $ratio = min($size / $srcW, $size / $srcH);
            $dstW  = (int)($srcW * $ratio);
            $dstH  = (int)($srcH * $ratio);
            $offX  = $x + (int)(($size - $dstW) / 2);
            $offY  = $y + (int)(($size - $dstH) / 2);

            imagecopyresampled($img, $logo, $offX, $offY, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($logo);
        } catch (\Throwable $e) {
            Log::warning("OgImage: failed to draw logo {$url}: " . $e->getMessage());
            $c = imagecolorallocate($img, 50, 50, 50);
            imagefilledellipse($img, $x + $size/2, $y + $size/2, $size, $size, $c);
        }
    }
}
