<?php

namespace App\Filament\Resources\Tutors\Schemas;

use Filament\Schemas\Schema;

class TutorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Section::make('Tutor Information')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('user.name')
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\TextInput::make('user.email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\TextInput::make('institution')
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\Select::make('subjects')
                            ->multiple()
                            ->options([
                                'Mathematics' => 'Mathematics',
                                'Physics' => 'Physics',
                                'Chemistry' => 'Chemistry',
                                'Biology' => 'Biology',
                                'English' => 'English',
                                'History' => 'History',
                                'Geography' => 'Geography',
                                'Computer Science' => 'Computer Science'
                            ])
                            ->required(),
                        \Filament\Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->maxLength(20),
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
