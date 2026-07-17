<?php

namespace craft\contentmigrations;

use Craft;
use craft\db\Migration;
use craft\elements\Entry;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;

/**
 * Seeds the Blog section and a couple of entries for the Edge caching demo.
 */
class m260717_000000_seed_blog extends Migration
{
    public function safeUp(): bool
    {
        $entries = Craft::$app->getEntries();

        // The section/entry type usually already exist via the committed project
        // config (applied during `craft install`); create them only when missing.
        $section = $entries->getSectionByHandle('blog');
        $entryType = $entries->getEntryTypeByHandle('post');

        if ($entryType === null || empty($entryType->getFieldLayout()?->getElementsByType(EntryTitleField::class))) {
            $entryType ??= new EntryType([
                'name' => 'Post',
                'handle' => 'post',
            ]);
            $entryType->hasTitleField = true;

            $fieldLayout = new FieldLayout(['type' => Entry::class]);
            $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $fieldLayout]);
            $tab->setElements([new EntryTitleField()]);
            $fieldLayout->setTabs([$tab]);
            $entryType->setFieldLayout($fieldLayout);

            if (!$entries->saveEntryType($entryType)) {
                throw new \RuntimeException('Could not save entry type: ' . implode(', ', $entryType->getErrorSummary(true)));
            }
        }

        if ($section === null) {
            $section = new Section([
                'name' => 'Blog',
                'handle' => 'blog',
                'type' => Section::TYPE_CHANNEL,
                'entryTypes' => [$entryType],
                'siteSettings' => [
                    new Section_SiteSettings([
                        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                        'enabledByDefault' => true,
                        'hasUrls' => true,
                        'uriFormat' => 'blog/{slug}',
                        'template' => 'blog/_entry',
                    ]),
                ],
            ]);
            if (!$entries->saveSection($section)) {
                throw new \RuntimeException('Could not save section: ' . implode(', ', $section->getErrorSummary(true)));
            }
        }

        if (Entry::find()->sectionId($section->id)->status(null)->exists()) {
            return true;
        }

        foreach (['Hello from the edge', 'Caching without footguns'] as $title) {
            $entry = new Entry([
                'sectionId' => $section->id,
                'typeId' => $entryType->id,
                'title' => $title,
                'slug' => str_replace(' ', '-', strtolower($title)),
            ]);
            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new \RuntimeException('Could not save entry: ' . implode(', ', $entry->getErrorSummary(true)));
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('blog');
        if ($section !== null) {
            Craft::$app->getEntries()->deleteSection($section);
        }

        return true;
    }
}
