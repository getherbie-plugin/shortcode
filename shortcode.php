<?php

namespace herbie\plugin\shortcode;

use Herbie\Site;
use herbie\plugin\shortcode\classes\Shortcode;
use Herbie\StringValue;
use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;

class ShortcodePlugin extends \Herbie\Plugin
{
    protected $events;
    protected $config;
    protected $shortcode;

    /**
     * @param EventManagerInterface $events
     * @param int $priority
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->events = $events;
        $events->attach('onPluginsInitialized', [$this, 'onPluginsInitialized'], $priority);
        $events->attach('onRenderContent', [$this, 'onRenderContent'], $priority);
    }

    /**
     * @param EventInterface $event
     */
    public function onPluginsInitialized(EventInterface $event)
    {
        $this->init();

        $this->addDateTag();
        $this->addPageTag();
        $this->addSiteTag();
        $this->addIncludeTag();
        $this->addTwigTag();
        $this->addLinkTag();
        $this->addEmailTag();
        $this->addTelTag();
        $this->addImageTag();
        $this->addFileTag();
        $this->addListingTag();
        $this->addBlocksTag();

        $this->events->trigger('onAddShortcode', $this->shortcode); // TODO do we need this event?
        $this->events->trigger('onShortcodeInitialized', $this->shortcode);
    }

    /**
     * @param EventInterface $event
     */
    public function onRenderContent(EventInterface $event)
    {
        /** @var StringValue $stringValue */
        $stringValue = $event->getTarget();
        $parsed = $this->shortcode->parse($stringValue->get());
        $stringValue->set($parsed);
    }

    protected function init()
    {
        $this->config = $this->herbie->getConfig();
        $tags = $this->config->get('plugins.config.shortcode', []);

        // Feature 20160224: Define simple shortcodes also in config.yml
        foreach ($tags as $tag => $_callable) {
            $tags[$tag] = create_function('$atts, $content', $_callable.';');
        }

        $this->shortcode = new Shortcode($tags);
        $this->herbie->setShortcode($this->shortcode);
    }

    public function getShortcodeObject()
    {
        return $this->shortcode;
    }

    public function add($tag, callable $callable)
    {
        $this->shortcode->add($tag, $callable);
    }

    public function getTags()
    {
        return $this->shortcode->getTags();
    }

    protected function addDateTag()
    {
        $this->add('date', function ($options) {
            if (is_string($options)) {
                $options = (array)$options;
            }
            $options = array_merge([
                'format' => empty($options[0]) ? '%x' : $options[0],
                'locale' => ''
            ], $options);
            if (!empty($options['locale'])) {
                setlocale(LC_TIME, $options['locale']);
            }
            return strftime($options['format']);
        });
    }

    protected function addPageTag()
    {
        $this->add('page', function ($options) {
            if (empty($options[0])) {
                return;
            }
            $name = ltrim($options[0], '.');
            $field = $this->herbie->getPage()->{$name};
            if (is_array($field)) {
                $delim = empty($options['join']) ? ' ' : $options['join'];
                return join($delim, $field);
            }
            return $field;
        });
    }

    protected function addSiteTag()
    {
        $this->add('site', function ($options) {
            if (empty($options[0])) {
                return;
            }
            $name = ltrim($options[0], '.');
            $site = new Site($this->herbie);
            $field = $site->{$name};
            if (is_array($field)) {
                $delim = empty($options['join']) ? ' ' : $options['join'];
                return join($delim, $field);
            }
            return $field;
        });
    }

    protected function addIncludeTag()
    {
        $this->add('include', function ($options) {

            $params = $options;

            $options = array_merge([
                'path' => empty($options[0]) ? '' : $options[0]
            ], $options);

            if (empty($options['path'])) {
                return;
            }

            if (isset($params['0'])) {
                unset($params['0']);
            }
            if (isset($params['path'])) {
                unset($params['path']);
            }

            return $this->herbie->getTwig()->render($options['path'], $params);
        });
    }

    protected function addListingTag()
    {
        $this->add('listing', function ($options) {

            $options = array_merge([
                'path' => '@widget/listing.twig',
                'filter' => '',
                'sort' => '',
                'shuffle' => false,
                'limit' => 10,
                'pagination' => true
            ], $options);

            $collection = $this->herbie->getMenuList();

            if (!empty($options['filter'])) {
                list($field, $value) = explode('|', $options['filter']);
                $collection = $collection->filter($field, $value);
            }

            if (!empty($options['sort'])) {
                list($field, $direction) = explode('|', $options['sort']);
                $collection = $collection->sort($field, $direction);
            }

            if (1 == (int)$options['shuffle']) {
                $collection = $collection->shuffle();
            }

            // filter pages with empty title
            $collection = $collection->filter(function (\Herbie\Menu\MenuItem $page) {
                return !empty($page->title);
            });

            $pagination = new \Herbie\Pagination($collection);
            $pagination->setLimit($options['limit']);

            return $this->getTwig()->render($options['path'], ['pagination' => $pagination]);
        });
    }

    protected function addBlocksTag()
    {
        $this->add('blocks', function ($options) {

            $options = array_merge([
                'path' => $this->getPage()->getDefaultBlocksPath(),
                'sort' => '',
                'shuffle' => 'false'
            ], (array)$options);

            // collect pages
            $extensions = $this->config->get('pages.extensions', []);
            $path = $options['path'];
            $paths = [$path => $this->getAlias()->get($path)];
            $pageBuilder = new Herbie\Menu\Builder($paths, $extensions);
            $collection = $pageBuilder->buildCollection();

            if (!empty($options['sort'])) {
                list($field, $direction) = explode('|', $options['sort']);
                $collection = $collection->sort($field, $direction);
            }

            if ('true' == strtolower($options['shuffle'])) {
                $collection = $collection->shuffle();
            }

            $twig = $this->getTwig();
            $extension = $this->getConfig()->get('layouts.extension');

            // store page
            $page = $this->getPage();

            $return = '';

            foreach ($collection as $i => $item) {
                $block = Herbie\Page::create($item->path);

                $this->herbie->setPage($block);

                if (empty($block->layout)) {
                    $return .= $twig->renderPageSegment(0, $block);
                } else {
                    $layout = empty($extension) ? $block->layout : sprintf('%s.%s', $block->layout, $extension);
                    // self-contained blocks aka widgets
                    if (file_exists($paths[$path].'/.layouts/'.$layout)) {
                        $layout = $path.'/.layouts/'.$layout;
                    }
                    $twig->getEnvironment()
                        ->getExtension('herbie\\plugin\\twig\\classes\\HerbieExtension')
                        ->setPage($block);
                    $return .= $twig->render($layout, ['block' => $block]);
                }
                $return .= "\n";
            }

            // restore page
            $twig->getEnvironment()
                ->getExtension('herbie\\plugin\\twig\\classes\\HerbieExtension')
                ->setPage($page);
            $this->herbie->setPage($page);

            return trim($return);
        });
    }

    protected function addTwigTag()
    {
        $this->add('twig', function ($options, $content) {
            return $this->herbie->getTwig()->renderString($content);
        });
    }

    protected function addEmailTag()
    {
        $this->add('email', function ($options) {

            $options = array_merge([
                'address' => empty($options[0]) ? '' : $options[0],
                'text' => '',
                'title' => '',
                'class' => ''
            ], $options);

            $attribs = [];
            $attribs['title'] = $options['title'];
            $attribs['class'] = $options['class'];
            $attribs = array_filter($attribs, 'strlen');

            $replace = [
                '{address}' => $options['address'],
                '{attribs}' => $this->buildHtmlAttributes($attribs),
                '{text}' => empty($options['text']) ? $options['address'] : $options['text']
            ];

            return strtr('<a href="mailto:{address}" {attribs}>{text}</a>', $replace);
        });
    }

    protected function addTelTag()
    {
        $this->add('tel', function ($options, $content) {
            return '';
        });
    }

    protected function addLinkTag()
    {
        $this->add('link', function ($options) {

            $options = array_merge([
                'href' => empty($options[0]) ? '' : $options[0],
                'text' => '',
                'title' => '',
                'class' => '',
                'target' => ''
            ], $options);

            $attribs = [];
            $attribs['title'] = $options['title'];
            $attribs['class'] = $options['class'];
            $attribs['target'] = $options['target'];
            $attribs = array_filter($attribs, 'strlen');

            // Interner Link
            if (strpos($options['href'], 'http') !== 0) {
                $options['href'] = $this->herbie->getUrlGenerator()->generate($options['href']);
            }

            $replace = [
                '{href}' => $options['href'],
                '{attribs}' => $this->buildHtmlAttributes($attribs),
                '{text}' => empty($options['text']) ? $options['href'] : $options['text']
            ];

            return strtr('<a href="{href}" {attribs}>{text}</a>', $replace);
        });
    }

    protected function addImageTag()
    {
        $this->add('image', function ($options) {
            $options = array_merge([
                'src' => empty($options[0]) ? '' : $options[0],
                'width' => '',
                'height' => '',
                'alt' => '',
                'class' => '',
                'link' => '', // @todo
                'caption' => ''
            ], $options);

            $attributes = $this->extractValuesFromArray(['width', 'height', 'alt', 'class'], $options);
            $attributes['alt'] = isset($attributes['alt']) ? $attributes['alt'] : '';

            // Interne Ressource
            if (strpos($options['src'], 'http') !== 0) {
                $options['src'] = $this->config->get('web.url') . '/' . $options['src'];
            }

            $replace = [
                '{src}' => $options['src'],
                '{attribs}' => $this->buildHtmlAttributes($attributes),
                '{caption}' => empty($options['caption']) ? '' : sprintf(
                    '<figcaption>%s</figcaption>',
                    $options['caption']
                )
            ];
            return strtr('<figure><img src="{src}" {attribs}>{caption}</figure>', $replace);
        });
    }

    protected function addFileTag()
    {
        $this->add('file', function ($options) {
            $options = array_merge([
                'path' => empty($options[0]) ? '' : $options[0],
                'title' => '',
                'text' => '',
                'alt' => '',
                'class' => '',
                'info' => 0
            ], $options);

            $attributes = $this->extractValuesFromArray(['title', 'text', 'alt', 'class'], $options);
            $attributes['alt'] = isset($attributes['alt']) ? $attributes['alt'] : '';

            $info = '';
            if (!empty($options['info'])) {
                $info = $this->getFileInfo($options['path']);
            }

            $replace = [
                '{href}' => $options['path'],
                '{attribs}' => $this->buildHtmlAttributes($attributes),
                '{text}' => empty($options['text']) ? $options['path'] : $options['text'],
                '{info}' => empty($info) ? '' : sprintf('<span class="file-info">%s</span>', $info)
            ];
            return strtr('<a href="{href}" {attribs}>{text}</a>{info}', $replace);
        });
    }

    protected function getFileInfo($path)
    {
        if (!is_readable($path)) {
            return '';
        }
        $replace = [
            '{size}' => $this->humanFilesize(filesize($path)),
            '{extension}' => strtoupper(pathinfo($path, PATHINFO_EXTENSION))
        ];
        return strtr(' ({extension}, {size})', $replace);
    }

    protected function humanFilesize($bytes, $decimals = 0)
    {
        $sz = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . $sz[$factor];
    }

    protected function extractValuesFromArray(array $values, array $array)
    {
        $extracted = [];
        foreach ($values as $key) {
            if (array_key_exists($key, $array)) {
                $extracted[$key] = $array[$key];
            }
        }
        return array_filter($extracted, 'strlen');
    }

    protected function buildHtmlAttributes($htmlOptions = [])
    {
        $attributes = '';
        foreach ($htmlOptions as $key => $value) {
            $attributes .= $key . '="' . $value . '" ';
        }
        return trim($attributes);
    }
}
