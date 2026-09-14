<?php

namespace Database\Seeders;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        // Usuário clássico Capivara Rogéria (Admin) - Sempre populado em qualquer ambiente
        User::updateOrCreate(
            ['username' => 'capivara.rogeria'],
            [
                'name' => 'Capivara Rogéria',
                'email' => 'capivara@rogeria.com',
                'password' => $password,
                'role' => UserRoles::Admin,
                'status' => UserStatuses::APPROVED,
                'birth' => '2023-12-10',
                'bio' => 'A capivara fundadora do CapiHouse!',
                'instagram' => 'capivara.rogeria',
            ]
        );

        // Em ambiente de produção, o único usuário que deve ser populado é a Capivara Rogéria
        if (app()->isProduction()) {
            return;
        }

        // 10 Usuários temáticos para testes em ambiente local
        $localTestUsers = [
            [
                'name' => 'Bento Capivara',
                'username' => 'bento',
                'email' => 'bento@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::APPROVED,
                'birth' => '2022-04-15',
                'bio' => 'Adoro tomar sol na beira do lago e mastigar grama fresca pela manhã.',
                'instagram' => 'bento.capi',
            ],
            [
                'name' => 'Pipoca Silvestre',
                'username' => 'pipoca',
                'email' => 'pipoca@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::APPROVED,
                'birth' => '2023-01-20',
                'bio' => 'A capivarinha mais animada da casa! Sempre pronta para um mergulho no rio.',
                'instagram' => 'pipoca_silvestre',
            ],
            [
                'name' => 'Tiago do Banhado',
                'username' => 'tiago',
                'email' => 'tiago@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::APPROVED,
                'birth' => '2021-08-11',
                'bio' => 'Fotógrafo amador de vitórias-régias e apreciador de banhos de lama relaxantes.',
                'instagram' => 'tiago_banhado',
            ],
            [
                'name' => 'Luna Capivarinha',
                'username' => 'luna',
                'email' => 'luna@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::APPROVED,
                'birth' => '2022-11-05',
                'bio' => 'Filósofa dos rios, observadora de borboletas e entusiasta de um bom som ambiente.',
                'instagram' => 'luna.capivarinha',
            ],
            [
                'name' => 'Chico Roedor',
                'username' => 'chico',
                'email' => 'chico@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::APPROVED,
                'birth' => '2020-03-30',
                'bio' => 'Chef de saladas aquáticas e mestre cuca oficial dos banquetes da CapiHouse.',
                'instagram' => 'chico_chef',
            ],
            [
                'name' => 'Maya do Rio',
                'username' => 'maya',
                'email' => 'maya@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::APPROVED,
                'birth' => '2022-07-18',
                'bio' => 'Violão, pôr do sol e boas vibrações à margem das águas. Amiga de todos!',
                'instagram' => 'maya_dorio',
            ],
            [
                'name' => 'Gabriel Banhado',
                'username' => 'gabriel',
                'email' => 'gabriel@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::APPROVED,
                'birth' => '2021-12-02',
                'bio' => 'Atleta de natação sincronizada da fauna local. Energia positiva sempre.',
                'instagram' => 'gabriel_banhado',
            ],
            [
                'name' => 'Olivia Mansa',
                'username' => 'olivia',
                'email' => 'olivia@capihouse.com',
                'role' => UserRoles::Admin,
                'status' => UserStatuses::APPROVED,
                'birth' => '2020-09-14',
                'bio' => 'Co-administradora das rodas de conversa e defensora da harmonia e paz no lago.',
                'instagram' => 'olivia_mansa',
            ],
            [
                'name' => 'Pedro Novato',
                'username' => 'pedro.novato',
                'email' => 'pedro@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::PENDING,
                'birth' => '2024-02-01',
                'bio' => 'Acabei de chegar no lago! Ansioso para ser aprovado e conhecer todo mundo.',
                'instagram' => 'pedro_novato',
            ],
            [
                'name' => 'Zeca Travesso',
                'username' => 'zeca.travesso',
                'email' => 'zeca@capihouse.com',
                'role' => UserRoles::User,
                'status' => UserStatuses::BANNED,
                'birth' => '2021-05-22',
                'bio' => 'Mordeu a toalha de piquenique e foi banido temporariamente para refletir.',
                'instagram' => 'zeca_travesso',
            ],
        ];

        foreach ($localTestUsers as $userData) {
            User::updateOrCreate(
                ['username' => $userData['username']],
                array_merge($userData, ['password' => $password])
            );
        }
    }
}
