<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** iframe saves arrive with no scripts and commands rewritten as parent.rcmail.* — this owns arming, mirroring into the parent, env publishing and map rescue. */
final class ClientBridge
{
    /** @var callable():array{map:array,installed:list<string>,base:string} set by the plugin at init */
    private $mapProvider;

    /** @var string|null folder to repaint after this save (rides inline) */
    private ?string $pendingPaintFolder = null;

    /** @var bool whether the map was published to env this request */
    private bool $mapPublished = false;

    /** One provider for every publish/republish/rescue — no snapshot can go stale. */
    public function provideMap(callable $provider): void
    {
        $this->mapProvider = $provider;
    }

    /** Inline the painter (iframe responses drop registered scripts) and mirror it into the parent. */
    public function arm(string $painterJs): void
    {
        $out = \rcmail::get_instance()->output;
        if (!$out || !method_exists($out, 'add_script')) {
            return;
        }

        if ($painterJs !== '') {
            $out->add_script($painterJs);
        }

        // The tree lives in the main window, not this settings iframe — mirror so iconizer_paint exists there.
        $out->add_script(self::mirrorJs($painterJs));
    }

    /** JSON string literal, NOT <script>-wrapped: add_script() emits the element itself and literal tags would close it early. */
    public static function mirrorJs(string $painterJs): string
    {
        $js = IconRenderer::inlineJson($painterJs);

        return "(function(){try{var p=window.parent;if(p&&p!==window&&p.document&&!p.__iconizerArmed){"
            . "var d=p.document.createElement('script');d.textContent=JSON.parse({$js});d.id='iconizer-painter';p.document.body.appendChild(d);p.__iconizerArmed=1;}}catch(e){}})();";
    }

    /** Publish map + packs to env (ready hook, html output only). */
    public function publish(): void
    {
        $rcmail = \rcmail::get_instance();
        if ($rcmail->output && $rcmail->output->type === 'html') {
            $this->setEnv();
            $this->mapPublished = true;
        }
    }

    /** Republish after a write so AJAX responses repaint the sidebar. */
    public function republish(): void
    {
        $this->setEnv();
    }

    /** Remember which row to repaint after this save (classic-POST hint). */
    public function notePaintHint(?string $folder): void
    {
        $this->pendingPaintFolder = $folder;
    }

    /** send_page net: the iframe has no rcmail of its own — write the map into the parent env synchronously, repaint a tick later so replace_folder_row lands first. */
    public function rescueMap($args)
    {
        $content = (string) ($args['content'] ?? '');
        $hint    = $this->pendingPaintFolder !== null && $this->pendingPaintFolder !== '';

        if (!$hint && ($this->mapPublished || strpos($content, 'iconizer_icons') !== false)) {
            return $args;
        }

        $pos = strripos($content, '</head>');
        if ($pos === false) {
            return $args;
        }

        $script = self::rescueScript(
            IconRenderer::inlineJson(($this->mapProvider)()['map']),
            $hint ? (string) $this->pendingPaintFolder : null
        );

        $args['content'] = substr_replace($content, $script, $pos, 0);

        return $args;
    }

    /** The inline rescue snippet: fresh map into the parent env, optional repaint tick. */
    public static function rescueScript(string $json, ?string $paintFolder): string
    {
        $script = "<script>(function(){var P=window.parent;"
            . "var r=(P&&P!==window&&P.rcmail)?P.rcmail:window.rcmail;"
            . "if(r&&r.env){r.env.iconizer_icons={$json};}})();";

        if ($paintFolder !== null && $paintFolder !== '') {
            $folder = IconRenderer::inlineJson($paintFolder);
            $script .= "window.setTimeout(function(){var P=window.parent;"
                . "var r=(P&&P!==window&&P.rcmail)?P.rcmail:window.rcmail;"
                . "if(r&&r.iconizer_paint){r.iconizer_paint({$folder});}},0);";
        }

        return $script . '</script>';
    }

    private function setEnv(): void
    {
        $rcmail = \rcmail::get_instance();
        if (!$rcmail->output) {
            return;
        }

        $data = ($this->mapProvider)();

        $rcmail->output->set_env('iconizer_icons', $data['map']);
        // Installed packs + sprite base drive the picker and <use href>.
        $rcmail->output->set_env('iconizer_installed', $data['installed']);
        $rcmail->output->set_env('iconizer_sprite_base', $data['base']);
    }
}
