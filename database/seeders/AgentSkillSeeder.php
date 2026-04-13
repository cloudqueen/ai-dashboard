<?php

namespace Database\Seeders;

use App\Models\AgentSkill;
use Illuminate\Database\Seeder;

class AgentSkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            [
                'name' => 'research',
                'display_name' => 'Research',
                'description' => 'Web research, information gathering, and summarization',
                'system_prompt' => 'You are a thorough research assistant. Gather comprehensive information and present it in a well-structured format with key findings, sources, and actionable insights.',
                'prompt_template' => "## Research Task\n\n{{ticket_content}}\n\n## Context\n\n{{context_notes}}\n\nProvide a comprehensive research report with:\n1. Key findings\n2. Relevant details and data\n3. Actionable recommendations\n4. Sources where applicable",
            ],
            [
                'name' => 'writing',
                'display_name' => 'Writing',
                'description' => 'Content drafting, editing, blog posts, documentation',
                'system_prompt' => 'You are a skilled writer. Create clear, engaging content that matches the requested style and tone.',
                'prompt_template' => "## Writing Task\n\n{{ticket_content}}\n\n## Context\n\n{{context_notes}}\n\nCreate well-structured, polished content as described above.",
            ],
            [
                'name' => 'coding',
                'display_name' => 'Coding',
                'description' => 'Code analysis, bug fixes, implementation',
                'system_prompt' => 'You are an expert software engineer. Analyze code, fix bugs, and implement features with clean, well-tested code.',
                'prompt_template' => "## Coding Task\n\n{{ticket_content}}\n\n## Context\n\n{{context_notes}}\n\nProvide:\n1. Analysis of the issue/requirement\n2. Implementation plan\n3. Code changes with explanations\n4. Testing considerations",
            ],
            [
                'name' => 'analysis',
                'display_name' => 'Analysis',
                'description' => 'Data analysis, decision support, strategic thinking',
                'system_prompt' => 'You are an analytical thinker. Break down complex problems, evaluate options, and provide data-driven recommendations.',
                'prompt_template' => "## Analysis Task\n\n{{ticket_content}}\n\n## Context\n\n{{context_notes}}\n\nProvide:\n1. Problem breakdown\n2. Key factors and considerations\n3. Options with pros/cons\n4. Recommended approach with reasoning",
            ],
            [
                'name' => 'email',
                'display_name' => 'Email',
                'description' => 'Email drafting and response composition',
                'system_prompt' => 'You are a professional communication assistant. Draft clear, appropriate emails that match the desired tone and achieve the communication goal.',
                'prompt_template' => "## Email Task\n\n{{ticket_content}}\n\n## Context\n\n{{context_notes}}\n\nDraft a professional email that:\n1. Is clear and concise\n2. Achieves the stated goal\n3. Has appropriate tone\n4. Includes a clear call to action if needed",
            ],
        ];

        foreach ($skills as $skill) {
            AgentSkill::updateOrCreate(
                ['name' => $skill['name']],
                $skill
            );
        }
    }
}
