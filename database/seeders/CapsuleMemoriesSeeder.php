<?php

namespace Database\Seeders;

use App\Models\CapsuleMemory;
use App\Models\CapsuleTag;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CapsuleMemoriesSeeder extends Seeder
{
    public function run(): void
    {
        // Pega usuários existentes para vincular participantes
        $admin = User::where('role', 'admin')->first() ?? User::first();
        $bento = User::where('username', 'bento')->first();
        $pipoca = User::where('username', 'pipoca')->first();

        if (! $admin) {
            return;
        }

        $now = Carbon::now();
        $todayMonth = $now->month;
        $todayDay = $now->day;

        // Tags do acervo
        $tagsData = [
            'Viagem' => '#a66130',
            'Perrengue Chique' => '#dc2626',
            'Amigos' => '#2563eb',
            'Família' => '#16a34a',
            'Casamento' => '#d97706',
            'Casa Nova' => '#9333ea',
            'Rolê' => '#0891b2',
            'Nostalgia' => '#b07a3b',
        ];

        $tags = [];
        foreach ($tagsData as $name => $color) {
            $tags[$name] = CapsuleTag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'color' => $color]
            );
        }

        // Lista de memórias ricas distribuídas ao longo dos anos
        $memories = [
            // Memória especial no mesmo dia de hoje há 2 anos (Garante o "Neste Dia")
            [
                'title' => 'Noite de Fondue e Conversas até de Madrugada',
                'content' => 'Estávamos todos reunidos na sala com o tempo frio lá fora. Decidimos improvisar um fondue com o que tinha na geladeira. O que era para ser um jantar rápido virou uma sessão de histórias antigas que durou até quase 4 da manhã, rindo das viagens do passado e traçando planos para os próximos anos. Registrando aqui para nunca esquecer a sensação acolhedora dessa noite.',
                'occurred_at' => Carbon::create($now->year - 2, $todayMonth, $todayDay)->format('Y-m-d'),
                'occurred_at_precision' => 'exact',
                'location_name' => 'Sala de Estar, Casa',
                'visibility' => 'shared',
                'is_favorite' => true,
                'tags' => ['Amigos', 'Nostalgia'],
                'participants' => [
                    ['user_id' => $bento?->id, 'name' => $bento?->name, 'role' => 'mestre cuca'],
                    ['user_id' => $pipoca?->id, 'name' => $pipoca?->name, 'role' => 'contadora de causos'],
                ],
            ],

            // Memória especial no mesmo dia de hoje há 4 anos
            [
                'title' => 'Decisão de Mudar de Apartamento e Novo Começo',
                'content' => "Foi exatamente neste dia que assinamos o contrato e pegamos as chaves. Estávamos com frio na barriga, a sala completamente vazia com cheiro de tinta fresca e pizza no chão comendo no pratinho descartável. Olhamos um pro outro e dissemos: 'Vai dar tudo certo'. Olhando hoje, foi um dos passos mais acertados que demos.",
                'occurred_at' => Carbon::create($now->year - 4, $todayMonth, $todayDay)->format('Y-m-d'),
                'occurred_at_precision' => 'exact',
                'location_name' => 'Apartamento Novo',
                'visibility' => 'public',
                'is_favorite' => true,
                'tags' => ['Casa Nova', 'Família'],
                'participants' => [
                    ['name' => 'Esposa', 'role' => 'parceira de vida'],
                ],
            ],

            // 2021
            [
                'title' => 'A Famosa Subida da Serra com o Carro Fervendo',
                'content' => 'Íamos passar o feriado na praia e, bem no meio da subida da serra sob um sol de 35 graus, o radiador resolveu abrir o bico com fumaça branca saindo pelo capô. Paramos no acostamento sem sinal de celular direito. No fim, conseguimos ajuda de um caminhoneiro gente boa com água, a gente esperando rindo da situação na beira da estrada. Virou a piada clássica de toda vez que alguém fala em viajar.',
                'occurred_at' => '2021-01-18',
                'occurred_at_precision' => 'exact',
                'location_name' => 'Serra do Mar, SP',
                'visibility' => 'public',
                'is_favorite' => false,
                'tags' => ['Perrengue Chique', 'Viagem'],
                'participants' => [
                    ['user_id' => $bento?->id, 'name' => $bento?->name, 'role' => 'motorista paciente'],
                ],
            ],

            // 2022
            [
                'title' => 'Viagem Inesquecível para Florianópolis e Trilha da Joaquina',
                'content' => 'Acordamos às 6h para pegar o nascer do sol nas dunas da Joaquina. O vento estava gelado mas a vista foi absurda de linda. Depois fomos comer frutos do mar no canal da Barra da Lagoa. Passamos a tarde rindo na areia e voltamos exaustos mas de alma renovada. Uma das melhores viagens da nossa vida.',
                'occurred_at' => '2022-03-12',
                'occurred_at_precision' => 'exact',
                'location_name' => 'Praia da Joaquina, Florianópolis - SC',
                'visibility' => 'public',
                'is_favorite' => true,
                'tags' => ['Viagem', 'Amigos'],
                'participants' => [
                    ['user_id' => $pipoca?->id, 'name' => $pipoca?->name, 'role' => 'fotógrafa'],
                    ['name' => 'Esposa', 'role' => 'parceira de trilha'],
                ],
            ],

            // 2022 (Data aproximada)
            [
                'title' => 'Carnaval na Casa de Praia com a Turma',
                'content' => 'Não lembro a data exata do dia do feriado, mas foi em meados de fevereiro de 2022. Chuva torrencial quase o tempo todo, então ficamos trancados jogando Uno, Banco Imobiliário e cozinhando panquecas. O que era pra ser sol na praia virou um dos carnavais mais divertidos que já passamos juntos.',
                'occurred_at' => '2022-02-28',
                'occurred_at_precision' => 'approximate',
                'approximate_date' => 'Carnaval de 2022',
                'location_name' => 'Litoral Norte, SP',
                'visibility' => 'public',
                'is_favorite' => false,
                'tags' => ['Rolê', 'Amigos'],
                'participants' => [
                    ['user_id' => $bento?->id, 'name' => $bento?->name, 'role' => 'campeão do Uno'],
                    ['user_id' => $pipoca?->id, 'name' => $pipoca?->name, 'role' => 'presente'],
                ],
            ],

            // 2023 (Memória Privada Compartilhada)
            [
                'title' => 'Conversa Sincera e Planos para o Futuro no Jardim',
                'content' => 'Uma conversa que mudou o rumo das nossas decisões sobre trabalho e moradia. Sentamos na varanda no final da tarde, colocamos tudo na mesa com calma e alinhamos o que queríamos construir juntos nos próximos 5 anos. Guardando em registro privado para reler quando bater dúvida de onde queremos chegar.',
                'occurred_at' => '2023-08-05',
                'occurred_at_precision' => 'exact',
                'location_name' => 'Varanda da Casa',
                'visibility' => 'private',
                'is_favorite' => true,
                'tags' => ['Família', 'Nostalgia'],
                'participants' => [
                    ['name' => 'Esposa', 'role' => 'co-autora'],
                ],
            ],

            // 2023
            [
                'title' => 'O Casamento do Ano e o Tombo na Pista de Dança',
                'content' => 'A cerimônia no fim de tarde foi emocionante demais, quase todo mundo chorou nos votos. Mas na festa, na hora que tocou o clássico dos anos 2000, alguém escorregou no gelo seco e derrubou uma mesa de doces inteira. Rimos tanto que a barriga doeu no dia seguinte. Uma noite épica que vai ficar na história do nosso grupo.',
                'occurred_at' => '2023-11-20',
                'occurred_at_precision' => 'exact',
                'location_name' => 'Espaço das Palmeiras',
                'visibility' => 'public',
                'is_favorite' => true,
                'tags' => ['Casamento', 'Rolê', 'Amigos'],
                'participants' => [
                    ['user_id' => $bento?->id, 'name' => $bento?->name, 'role' => 'padrinho'],
                    ['user_id' => $pipoca?->id, 'name' => $pipoca?->name, 'role' => 'madrinha'],
                ],
            ],

            // 2024
            [
                'title' => 'Primeira Pizza no Forno Novo com Todo Mundo',
                'content' => 'Estreamos o forno de pedra na cozinha. Cada um montou a sua própria pizza com as combinações mais absurdas possíveis (incluindo abacaxi com gorgonzola que surpreendentemente ficou bom). A cozinha ficou uma bagunça de farinha até o teto, mas o sabor e a companhia compensaram cada minuto de limpeza depois.',
                'occurred_at' => '2024-06-15',
                'occurred_at_precision' => 'exact',
                'location_name' => 'Cozinha da Capihouse',
                'visibility' => 'public',
                'is_favorite' => false,
                'tags' => ['Amigos', 'Casa Nova'],
                'participants' => [
                    ['user_id' => $bento?->id, 'name' => $bento?->name, 'role' => 'pizzaiolo'],
                    ['user_id' => $pipoca?->id, 'name' => $pipoca?->name, 'role' => 'degustadora'],
                ],
            ],
        ];

        foreach ($memories as $data) {
            $tagNames = $data['tags'] ?? [];
            $participantsData = $data['participants'] ?? [];
            unset($data['tags'], $data['participants']);

            $memory = CapsuleMemory::updateOrCreate(
                [
                    'user_id' => $admin->id,
                    'title' => $data['title'],
                ],
                array_merge($data, [
                    'user_id' => $admin->id,
                ])
            );

            // Tags
            $tagIds = [];
            foreach ($tagNames as $tName) {
                if (isset($tags[$tName])) {
                    $tagIds[] = $tags[$tName]->id;
                }
            }
            $memory->tags()->sync($tagIds);

            // Participantes
            $memory->participants()->delete();
            foreach ($participantsData as $part) {
                $memory->participants()->create([
                    'user_id' => $part['user_id'] ?? null,
                    'name' => $part['name'] ?? null,
                    'role' => $part['role'] ?? 'presente',
                ]);
            }
        }
    }
}
