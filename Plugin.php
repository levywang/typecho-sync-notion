<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 将 Typecho 文章同步到 Notion
 * 
 * @package TypechoSyncNotion
 * @author levywang
 * @version 1.0.0
 * @link https://github.com/levywang/typecho-sync-notion
 */
class TypechoSyncNotion_Plugin implements Typecho_Plugin_Interface
{
    const NOTION_API_VERSION = '2022-06-28';
    const NOTION_API_BASE = 'https://api.notion.com/v1';
    
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate()
    {
        Typecho_Plugin::factory('admin/write-post.php')->option = array(__CLASS__, 'render');
        Typecho_Plugin::factory('admin/write-page.php')->option = array(__CLASS__, 'render');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'sync');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array(__CLASS__, 'sync');
        
        return _t('插件启用成功');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     */
    public static function deactivate()
    {
        return _t('插件禁用成功');
    }
    
    /**
     * 获取插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $notionToken = new Typecho_Widget_Helper_Form_Element_Text(
            'notionToken', 
            NULL, 
            '', 
            _t('Notion API Token'), 
            _t('请输入您的 Notion API Token，一般以ntn_开头
                API配置页面: https://www.notion.so/profile/integrations/')
        );
        $notionToken->addRule('required', _t('Notion API Token 不能为空'));
        $form->addInput($notionToken);
        
        $databaseId = new Typecho_Widget_Helper_Form_Element_Text(
            'databaseId', 
            NULL, 
            '',
            _t('Notion 数据库 ID'),
            _t('请输入您要同步到的 Notion 数据库 ID。<br/>'.
               '请确保您已经完成以下步骤：<br/>'.
               '1. 在 Notion 中创建一个数据库页面<br/>'.
               '2. 在数据库页面中点击右上角的"Share"按钮<br/>'.
               '3. 点击"Add connections"，选择您创建的集成<br/>'.
               '4. 复制数据库页面的 URL，从中提取数据库 ID')
        );
        $databaseId->addRule('required', _t('数据库 ID 不能为空'));
        $form->addInput($databaseId);
    }
    
    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
    
    /**
     * 插件实现方法
     */
    public static function render()
    {
        echo '<tr><td><label class="typecho-label" for="syncToNotion">同步到 Notion</label></td>
              <td colspan="3"><div><input type="checkbox" id="syncToNotion" name="syncToNotion" value="1" />
              <label for="syncToNotion">将此文章同步到 Notion</label></div></td></tr>';
    }
    
    /**
     * 同步文章到 Notion
     */
    public static function sync($contents, $class)
    {
        if (!isset($_POST['syncToNotion']) || $_POST['syncToNotion'] != 1) return;

        $config = Typecho_Widget::widget('Widget_Options')->plugin('TypechoSyncNotion');
        $databaseId = str_replace('-', '', $config->databaseId);
        
        if (self::checkExistingArticle($databaseId, $contents['title'], $config->notionToken)) {
            throw new Typecho_Plugin_Exception("文章《{$contents['title']}》已存在于 Notion 中");
        }

        $blocks = self::parseMarkdownToBlocks($contents['text']);
        $page = self::createNotionPage([
            'parent' => ['database_id' => $databaseId],
            'properties' => [
                'title' => [
                    'title' => [[
                        'text' => ['content' => $contents['title']]
                    ]]
                ]
            ],
            'children' => array_slice($blocks, 0, 100)
        ], $config->notionToken);

        if (count($blocks) > 100) {
            self::appendRemainingBlocks($page['id'], array_slice($blocks, 100), $config->notionToken);
        }

        return $contents;
    }

    private static function request($endpoint, $data, $token)
    {
        $ch = curl_init(self::NOTION_API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Notion-Version: ' . self::NOTION_API_VERSION,
                'Content-Type: application/json'
            ]
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $error = json_decode($result, true);
            throw new Typecho_Plugin_Exception('同步到 Notion 失败：' . ($error['message'] ?? '未知错误'));
        }
        
        return json_decode($result, true);
    }

    private static function checkExistingArticle($databaseId, $title, $token)
    {
        $result = self::request("/databases/$databaseId/query", [
            'filter' => [
                'property' => 'title',
                'title' => ['equals' => $title]
            ]
        ], $token);
        return !empty($result['results']);
    }

    private static function createNotionPage($data, $token)
    {
        return self::request('/pages', $data, $token);
    }

    private static function appendRemainingBlocks($pageId, $blocks, $token)
    {
        foreach (array_chunk($blocks, 100) as $batch) {
            self::request("/blocks/$pageId/children", ['children' => $batch], $token);
        }
    }

    private static function parseMarkdownToBlocks($markdown)
    {
        $blocks = [];
        $lines = explode("\n", preg_replace('/<!--markdown-->/', '', $markdown));
        $context = ['inCode' => false, 'content' => '', 'inTable' => false, 'tableData' => []];

        foreach ($lines as $line) {
            $line = rtrim($line);
            $block = self::parseLine($line, $context, $lines);
            if ($block) {
                // 确保返回的是数组
                if (isset($block[0])) {
                    $blocks = array_merge($blocks, $block);
                } else {
                    $blocks[] = $block;
                }
            }
        }

        // 处理未闭合的内容
        if ($context['inCode']) {
            $block = self::createCodeBlock($context['content'], $context['language']);
            if (isset($block[0])) {
                $blocks = array_merge($blocks, $block);
            } else {
                $blocks[] = $block;
            }
        }
        if ($context['inTable']) {
            $block = self::createTableBlock($context['tableData']);
            if ($block) {
                $blocks[] = $block;
            }
        }

        return array_values($blocks); // 确保返回索引数组
    }

    private static function parseLine(&$line, &$context, $lines)
    {
        // 处理代码块
        if (preg_match('/^```(\w*)/', $line, $matches)) {
            if (!$context['inCode']) {
                $context['inCode'] = true;
                $context['content'] = '';
                $context['language'] = $matches[1];
                return null;
            }
            $block = self::createCodeBlock($context['content'], $context['language']);
            $context['inCode'] = false;
            return $block;
        }

        if ($context['inCode']) {
            $context['content'] .= ($context['content'] ? "\n" : '') . $line;
            return null;
        }

        // 处理表格
        if (preg_match('/^\|(.+)\|$/', $line)) {
            if (!preg_match('/^[\|\s-:]+$/', $line)) {
                $cells = array_map('trim', explode('|', trim($line, '|')));
                $context['tableData'][] = $cells;
                $context['inTable'] = true;
                return null;
            }
            return null;
        }

        if ($context['inTable'] && trim($line) === '') {
            $block = self::createTableBlock($context['tableData']);
            $context['inTable'] = false;
            $context['tableData'] = [];
            return $block;
        }

        // 处理其他格式
        return self::parseOtherFormats($line, $lines);
    }

    private static function createCodeBlock($content, $language)
    {
        if (strlen($content) <= 1900) {
            return array(
                'object' => 'block',
                'type' => 'code',
                'code' => array(
                    'rich_text' => array(array(
                        'type' => 'text',
                        'text' => array('content' => $content)
                    )),
                    'language' => $language ?: 'plain text'
                )
            );
        } else {
            // 长代码块使用文本格式
            return array_map(function($chunk) {
                return array(
                    'object' => 'block',
                    'type' => 'paragraph',
                    'paragraph' => array(
                        'rich_text' => array(array(
                            'type' => 'text',
                            'text' => array('content' => $chunk),
                            'annotations' => array(
                                'code' => true,
                                'color' => 'gray'
                            )
                        ))
                    )
                );
            }, str_split($content, 1900));
        }
    }

    private static function createTableBlock($tableData)
    {
        if (empty($tableData)) return null;
        
        return array(
            'object' => 'block',
            'type' => 'table',
            'table' => array(
                'table_width' => count($tableData[0]),
                'has_column_header' => true,
                'has_row_header' => false,
                'children' => array_map(function($row) {
                    return array(
                        'object' => 'block',
                        'type' => 'table_row',
                        'table_row' => array(
                            'cells' => array_map(function($cell) {
                                return [array(
                                    'type' => 'text',
                                    'text' => array(
                                        'content' => str_replace('<br />', "\n", $cell)
                                    )
                                )];
                            }, $row)
                        )
                    );
                }, $tableData)
            )
        );
    }

    private static function parseOtherFormats($line, $lines)
    {
        // 处理图片
        if (preg_match('/!\[(.*?)\]\((.*?)\)/', $line, $matches) || 
            preg_match('/!\[(.*?)\]\[(\d+)\]/', $line, $matches)) {
            
            $imageUrl = '';
            $imageCaption = $matches[1];
            
            if (isset($matches[2])) {
                if (is_numeric($matches[2])) {
                    // 处理引用格式的图片
                    $refKey = '[' . $matches[2] . ']:';
                    foreach ($lines as $refLine) {
                        if (strpos($refLine, $refKey) === 0) {
                            $imageUrl = trim(substr($refLine, strlen($refKey)));
                            break;
                        }
                    }
                } else {
                    $imageUrl = $matches[2];
                }
            }
            
            if ($imageUrl) {
                return array(
                    'object' => 'block',
                    'type' => 'image',
                    'image' => array(
                        'type' => 'external',
                        'external' => array('url' => $imageUrl),
                        'caption' => array(array(
                            'type' => 'text',
                            'text' => array('content' => $imageCaption)
                        ))
                    )
                );
            }
        }

        // 处理标题
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
            $level = strlen($matches[1]);
            return array(
                'object' => 'block',
                'type' => "heading_$level",
                "heading_$level" => array(
                    'rich_text' => array(array(
                        'type' => 'text',
                        'text' => array('content' => $matches[2])
                    ))
                )
            );
        }

        // 处理列表
        if (preg_match('/^(\s*)([-*+]|\d+\.)\s+(.+)$/', $line, $matches)) {
            $type = is_numeric(rtrim($matches[2], '.')) ? 'numbered_list_item' : 'bulleted_list_item';
            return array(
                'object' => 'block',
                'type' => $type,
                $type => array(
                    'rich_text' => array(array(
                        'type' => 'text',
                        'text' => array('content' => $matches[3])
                    ))
                )
            );
        }

        // 处理引用
        if (preg_match('/^>\s*(.+)$/', $line, $matches)) {
            return array(
                'object' => 'block',
                'type' => 'quote',
                'quote' => array(
                    'rich_text' => array(array(
                        'type' => 'text',
                        'text' => array('content' => $matches[1])
                    ))
                )
            );
        }

        // 处理普通段落
        if (trim($line) !== '') {
            return array(
                'object' => 'block',
                'type' => 'paragraph',
                'paragraph' => array(
                    'rich_text' => array(array(
                        'type' => 'text',
                        'text' => array('content' => $line)
                    ))
                )
            );
        }

        return null;
    }
} 