<?php

namespace App\Livewire\Concerns;

/**
 * Points a Livewire component's paginator at this project's own views.
 *
 * Necessary because Livewire does not read Paginator::defaultView(): it
 * overrides it with `livewire::tailwind` for every component that paginates,
 * unless the component says otherwise. So the global default fixes the plain
 * Blade pages and leaves every Livewire page still rendering a Tailwind view
 * this app has no Tailwind for.
 *
 * The visible result of that gap was a chevron the height of the viewport on
 * the Gate 2 scenes page: `hidden sm:flex` hid nothing and `w-5 h-5` sized
 * nothing, so an inline SVG expanded to fill its container.
 *
 * A trait rather than a method on one component, because the next Livewire
 * component that paginates will hit exactly the same thing and there is nothing
 * in Livewire's behaviour to warn whoever writes it.
 */
trait PaginatesWithProjectTheme
{
    public function paginationView(): string
    {
        return 'pagination.narra-livewire';
    }

    public function paginationSimpleView(): string
    {
        return 'pagination.narra-livewire';
    }
}
