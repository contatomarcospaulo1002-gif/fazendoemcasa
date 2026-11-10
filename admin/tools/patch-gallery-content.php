<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/content.json';
$content = json_decode((string) file_get_contents($path), true);

$content['gallery.photos.title'] = 'Algumas histórias <span class="heading-accent">no programa</span>';
$content['gallery.photos.lead'] = 'Fotografias autorizadas pelas pessoas que aparecem. Desliza para o lado — o carrossel também avança sozinho.';
$content['gallery.photos.cta.text'] = 'Quero o guia 28 dias em casa por 14,90 €';
$content['gallery.photos.cta.href'] = 'https://pay.kiwify.com/5NJmQkR';

$content['gallery.comments.title'] = 'O que dizem <span class="heading-accent">sobre o guia</span>';
$content['gallery.comments.lead'] = 'Capturas ou textos autorizados. Desliza com o dedo no telemóvel.';
$content['gallery.comments.cta.text'] = 'Quero começar o plano';
$content['gallery.comments.cta.href'] = 'https://pay.kiwify.com/5NJmQkR';

for ($i = 1; $i <= 6; $i++) {
    $content["gallery.photos.s{$i}.before"] = ['src' => '', 'alt' => ''];
    $content["gallery.photos.s{$i}.after"] = ['src' => '', 'alt' => ''];
    $content["gallery.photos.s{$i}.caption"] = '';
    $content["gallery.comments.s{$i}.image"] = ['src' => '', 'alt' => ''];
    $content["gallery.comments.s{$i}.text"] = '';
}

file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "OK\n";
