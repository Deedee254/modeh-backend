<?php

namespace App\Filament\Resources\Quizees\Schemas;

use Filament\Schemas\Schema;

class QuizeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Section::make('Quizee Information')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('user.name')
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\TextInput::make('user.email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\TextInput::make('institution')
                            ->label('School/Institution')
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\Select::make('grade')
                            ->options(array_combine(range(1, 12), range(1, 12)))
                            ->required(),
                        \Filament\Forms\Components\TextInput::make('parent_email')
                            ->label('Parent/Guardian Email')
                            ->email()
                            ->maxLength(255),
                    ]),
                \Filament\Forms\Components\Section::make('Authentication')
                    ->schema([
                        \Filament\Forms\Components\Select::make('user.social_provider')
                            ->label('Login Method')
                            ->options([
                                'google' => 'Google',
                                null => 'Email',
                            ])
                            ->disabled(),
                        \Filament\Forms\Components\Toggle::make('user.is_profile_completed')
                            ->label('Profile Completed')
                            ->disabled(),
                    ])
            ]);
    }
}
