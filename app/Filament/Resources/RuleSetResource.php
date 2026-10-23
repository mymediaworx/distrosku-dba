<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RuleSetResource\Pages;
use App\Models\Instance;
use App\Models\RuleSet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RuleSetResource extends Resource
{
    protected static ?string $model = RuleSet::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Global rules';

    protected static ?string $navigationLabel = 'Rule sets';

    protected static ?string $modelLabel = 'rule set';

    public static function form(Form $form): Form
    {
        $json = fn (string $k) => Forms\Components\Textarea::make("sets.$k")->label(RuleSet::SETS[$k])->rows(12)
            ->extraInputAttributes(['style' => 'font-family:ui-monospace,monospace;font-size:12px'])
            ->helperText('JSON list of {"code", "overridable", "data":{...}}. Rows are upserted by code on the ERP and never deleted.')
            ->rule(fn () => function ($attr, $value, $fail) {
                $d = json_decode((string) $value, true);
                if (! is_array($d) || ! array_is_list($d)) {
                    $fail('Must be a JSON list.');
                }
            });

        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\TextInput::make('version')->numeric()->disabled()->dehydrated(false)->placeholder('assigned on save'),
                Forms\Components\TextInput::make('name')->required()->maxLength(120)->columnSpan(2),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
            Forms\Components\Tabs::make('Sets')->columnSpanFull()->tabs(
                collect(RuleSet::SETS)->map(fn ($label, $k) => Forms\Components\Tabs\Tab::make($label)->schema([$json($k)]))->values()->all()
            ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('version', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version')->prefix('v')->sortable()->weight('semibold'),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => $state === 'published' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('rows')->label('Rows')->state(fn (RuleSet $r) => collect($r->counts())->map(fn ($n, $k) => RuleSet::SETS[$k].': '.$n)->implode(' · '))->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('instances')->label('On instances')
                    ->state(fn (RuleSet $r) => Instance::where('rules_version', $r->version)->count()),
                Tables\Columns\TextColumn::make('published_at')->dateTime()->placeholder('—')->visibleFrom('md'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\EditAction::make()->iconButton()->visible(fn (RuleSet $r) => $r->status === 'draft'),
                Tables\Actions\Action::make('push')->label('Push')->icon('heroicon-o-paper-airplane')->color('primary')
                    ->visible(fn (RuleSet $r) => $r->status === 'published')
                    ->url(fn (RuleSet $r) => Pages\PushRuleSet::getUrl(['record' => $r])),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(['default' => 2, 'lg' => 4])->schema([
                Infolists\Components\TextEntry::make('version')->prefix('v'),
                Infolists\Components\TextEntry::make('status')->badge()->color(fn ($state) => $state === 'published' ? 'success' : 'gray'),
                Infolists\Components\TextEntry::make('published_at')->dateTime()->placeholder('—'),
                Infolists\Components\TextEntry::make('created_by')->placeholder('—'),
                Infolists\Components\TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Changes from the previous version')->schema([
                Infolists\Components\ViewEntry::make('diff')->view('filament.rule-diff')
                    ->state(fn (RuleSet $r) => \App\Services\RuleDiff::between(RuleSet::where('version', '<', $r->version)->orderByDesc('version')->first(), $r)),
            ])->collapsible(),
            Infolists\Components\Section::make('Rows')->collapsed()->schema(
                collect(RuleSet::SETS)->map(fn ($label, $k) => Infolists\Components\RepeatableEntry::make("payload.$k")->label($label.' ('.'codes'.')')
                    ->schema([
                        Infolists\Components\TextEntry::make('code')->hiddenLabel()->fontFamily('mono'),
                        Infolists\Components\IconEntry::make('overridable')->boolean()->label('Overridable'),
                    ])->columns(2)->grid(['default' => 1, 'lg' => 2]))->values()->all()
            ),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRuleSets::route('/'),
            'create' => Pages\CreateRuleSet::route('/create'),
            'view' => Pages\ViewRuleSet::route('/{record}'),
            'edit' => Pages\EditRuleSet::route('/{record}/edit'),
            'push' => Pages\PushRuleSet::route('/{record}/push'),
        ];
    }
}
