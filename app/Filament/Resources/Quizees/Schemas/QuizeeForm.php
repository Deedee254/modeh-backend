<?php

namespace App\Filament\Resources\Quizees\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

class QuizeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Quizee Information')
                    ->schema([
                        TextInput::make('user.name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('user.email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('institution')
                            ->label('School/Institution')
                            ->required()
                            ->maxLength(255),
                        Select::make('grade')
                            ->options(array_combine(range(1, 12), range(1, 12)))
                            ->required(),
                        TextInput::make('parent_email')
                            ->label('Parent/Guardian Email')
                            ->email()
                            ->maxLength(255),
                    ]),
                Section::make('Authentication')
                    ->schema([
                        Select::make('user.social_provider')
                            ->label('Login Method')
                            ->options([
                                'google' => 'Google',
                                null => 'Email',
                            ])
                            ->disabled(),
                        Toggle::make('user.is_profile_completed')
                            ->label('Profile Completed')
                            ->disabled(),
                    ])
            ]);
    }
}
