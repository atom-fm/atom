<?php

declare(strict_types=1);

namespace Atom\Components;

use Atom\Module\ModuleContext;
use Atom\Components\Alert;
use Atom\Components\AppShell;
use Atom\Components\Avatar;
use Atom\Components\Badge;
use Atom\Components\Breadcrumb;
use Atom\Components\Breadcrumbs;
use Atom\Components\Button;
use Atom\Components\ButtonGroup;
use Atom\Components\Card;
use Atom\Components\CheckField;
use Atom\Components\Column;
use Atom\Components\ControlGroup;
use Atom\Components\Dialog;
use Atom\Components\Details;
use Atom\Components\Divider;
use Atom\Components\Drawer;
use Atom\Components\EmptyState;
use Atom\Components\Field;
use Atom\Components\FieldError;
use Atom\Components\FileField;
use Atom\Components\Form;
use Atom\Components\FormActions;
use Atom\Components\FormRow;
use Atom\Components\FormSection;
use Atom\Components\HiddenField;
use Atom\Components\Icon;
use Atom\Components\Inline;
use Atom\Components\InputGroup;
use Atom\Components\Kbd;
use Atom\Components\ListComponent;
use Atom\Components\ListItem;
use Atom\Components\Panel;
use Atom\Components\PageHeader;
use Atom\Components\Pagination;
use Atom\Components\PasswordField;
use Atom\Components\Progress;
use Atom\Components\RadioField;
use Atom\Components\SelectField;
use Atom\Components\Sidebar;
use Atom\Components\SidebarGroup;
use Atom\Components\SidebarItem;
use Atom\Components\SnackBar;
use Atom\Components\Skeleton;
use Atom\Components\Spinner;
use Atom\Components\SplitView;
use Atom\Components\Stack;
use Atom\Components\Stats;
use Atom\Components\StatusDot;
use Atom\Components\SwitchField;
use Atom\Components\Tab;
use Atom\Components\Tabs;
use Atom\Components\Table;
use Atom\Components\Tag;
use Atom\Components\TextArea;
use Atom\Components\TextAreaField;
use Atom\Components\TextField;
use Atom\Components\TextInput;
use Atom\Components\Toast;
use Atom\Components\Toolbar;
use Atom\Components\ValidationSummary;
use Atom\Router\RouteEntry;
use Atom\View\Component\ComponentSet;

final readonly class Components
{
    public const DEFAULT_RESOURCE_PATH = "/atom/components/resources";
    public const STYLES_VERSION = "12";
    public const SCRIPTS_VERSION = "2";

    public static function module(string $resourcePath = self::DEFAULT_RESOURCE_PATH): ComponentsModule
    {
        return new ComponentsModule($resourcePath);
    }

    public static function definitions(): ComponentSet
    {
        return ComponentSet::from([
            "Alert" => Alert::class,
            "AppShell" => AppShell::class,
            "Avatar" => Avatar::class,
            "Badge" => Badge::class,
            "Breadcrumb" => Breadcrumb::class,
            "Breadcrumbs" => Breadcrumbs::class,
            "Button" => Button::class,
            "ButtonGroup" => ButtonGroup::class,
            "Card" => Card::class,
            "CheckField" => CheckField::class,
            "Column" => Column::class,
            "ComponentsStyles" => ComponentsStyles::class,
            "ComponentsScripts" => ComponentsScripts::class,
            "ControlGroup" => ControlGroup::class,
            "Dialog" => Dialog::class,
            "Details" => Details::class,
            "Divider" => Divider::class,
            "Drawer" => Drawer::class,
            "EmptyState" => EmptyState::class,
            "Field" => Field::class,
            "FieldError" => FieldError::class,
            "FileField" => FileField::class,
            "Form" => Form::class,
            "FormView" => FormView::class,
            "FormActions" => FormActions::class,
            "FormRow" => FormRow::class,
            "FormSection" => FormSection::class,
            "HiddenField" => HiddenField::class,
            "Icon" => Icon::class,
            "Inline" => Inline::class,
            "InputGroup" => InputGroup::class,
            "Kbd" => Kbd::class,
            "List" => ListComponent::class,
            "ListItem" => ListItem::class,
            "Navbar" => Navbar::class,
            "Panel" => Panel::class,
            "PageHeader" => PageHeader::class,
            "Pagination" => Pagination::class,
            "PasswordField" => PasswordField::class,
            "Progress" => Progress::class,
            "RadioField" => RadioField::class,
            "SelectField" => SelectField::class,
            "Sidebar" => Sidebar::class,
            "SidebarGroup" => SidebarGroup::class,
            "SidebarItem" => SidebarItem::class,
            "SnackBar" => SnackBar::class,
            "Skeleton" => Skeleton::class,
            "Spinner" => Spinner::class,
            "SplitView" => SplitView::class,
            "Stack" => Stack::class,
            "Stats" => Stats::class,
            "StatusDot" => StatusDot::class,
            "SwitchField" => SwitchField::class,
            "Tab" => Tab::class,
            "Tabs" => Tabs::class,
            "Table" => Table::class,
            "Tag" => Tag::class,
            "TextArea" => TextArea::class,
            "TextAreaField" => TextAreaField::class,
            "TextField" => TextField::class,
            "TextInput" => TextInput::class,
            "Toast" => Toast::class,
            "Toolbar" => Toolbar::class,
            "ValidationSummary" => ValidationSummary::class,
        ]);
    }

    /**
     * @return RouteEntry[]
     */
    public static function resources(ModuleContext $context, string $path = self::DEFAULT_RESOURCE_PATH): array
    {
        return $context->resources($path, __DIR__ . "/Resources");
    }
}
