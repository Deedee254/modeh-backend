<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TopicSeeder extends Seeder
{
    public function run(): void
    {
        // Key topics per grade, grouped by logical subject areas
        $topics = [
            1 => [
                'Language Activities' => [
                    'Letter sounds & word building',
                ],
                'Mathematical Activities' => [
                    'Numbers (0–20)',
                ],
                'Environmental Activities' => [
                    'Myself & my immediate environment',
                ],
                'Hygiene & Nutrition' => [
                    'Healthy eating & hygiene practices',
                ],
                'Movement & Creative Activities' => [
                    'Singing, drawing, simple games',
                ],
            ],
            2 => [
                'Mathematics' => [
                    'Numbers (up to 100)',
                ],
                'English' => [
                    'Reading comprehension',
                ],
                'Environmental Activities' => [
                    'Weather & seasons',
                ],
                'Religious Education (CRE/IRE/HRE)' => [
                    'Respect & kindness in religion',
                ],
                'Art & Craft' => [
                    'Drawing & coloring',
                ],
                'Music' => [
                    'Traditional songs & instruments',
                ],
                'Movement & Physical Activities' => [
                    'Games & body movement',
                ],
            ],
            3 => [
                'Mathematics' => [
                    'Fractions & measurements',
                ],
                'English' => [
                    'Sentence construction',
                ],
                'Environmental Activities' => [
                    'Plants, animals, environment',
                ],
                'Religious Education' => [
                    'Religious values & practices',
                ],
                'Art & Craft' => [
                    'Modeling & collage',
                ],
                'Music' => [
                    'Rhythm & instruments',
                ],
                'Movement & Physical Activities' => [
                    'Team games, athletics',
                ],
                'Pastoral Program of Instruction (PPI)' => [
                    'PPI activities',
                ],
            ],
            4 => [
                'Mathematics' => [
                    'Whole numbers & fractions',
                ],
                'English' => [
                    'Composition writing',
                ],
                'Science & Technology' => [
                    'Matter, energy, simple machines',
                ],
                'Social Studies' => [
                    'Maps of Kenya, people & regions',
                ],
                'Agriculture' => [
                    'Crop farming basics',
                ],
                'Religious Education' => [
                    'Moral teachings',
                ],
                'Creative Arts (Art, Craft, Music)' => [
                    'Design, painting, singing',
                ],
                'Physical & Health Education' => [
                    'Games & physical fitness',
                ],
                'Life Skills' => [
                    'Self-awareness & decision making',
                ],
            ],
            5 => [
                'Mathematics' => [
                    'Decimals & percentages',
                ],
                'English' => [
                    'Reading comprehension & grammar',
                ],
                'Science & Technology' => [
                    'Human body, health, environment',
                ],
                'Social Studies' => [
                    'Government & citizenship',
                ],
                'Agriculture' => [
                    'Animal husbandry',
                ],
                'Religious Education' => [
                    'Faith & ethics',
                ],
                'Creative Arts' => [
                    'Drama, design & crafts',
                ],
                'Physical & Health Education' => [
                    'Sports & safety',
                ],
                'Life Skills' => [
                    'Leadership & problem solving',
                ],
            ],
            6 => [
                'Mathematics' => [
                    'Algebraic concepts',
                ],
                'English' => [
                    'Essay writing & oral skills',
                ],
                'Science & Technology' => [
                    'Energy, electricity, environment conservation',
                ],
                'Social Studies' => [
                    'History & heritage of Kenya',
                ],
                'Agriculture' => [
                    'Advanced farming practices',
                ],
                'Religious Education' => [
                    'Religious values & tolerance',
                ],
                'Creative Arts' => [
                    'Art, dance, theatre',
                ],
                'Physical & Health Education' => [
                    'Sports science & fitness',
                ],
                'Life Skills' => [
                    'Conflict resolution, career awareness',
                ],
            ],
            7 => [
                'English' => [
                    'Listening & Speaking',
                    'Reading comprehension',
                    'Grammar',
                    'Writing skills',
                    'Literature (poems, short stories)',
                ],
                'Kiswahili' => [
                    'Kusikiliza na Kuzungumza',
                    'Kusoma',
                    'Sarufi',
                    'Kuandika',
                    'Fasihi simulizi',
                ],
                'Mathematics' => [
                    'Numbers',
                    'Algebra',
                    'Geometry',
                    'Measurement',
                    'Data handling',
                    'Fractions & Decimals',
                ],
                'Integrated Science' => [
                    'Matter',
                    'Energy',
                    'Plants & Animals',
                    'Human body',
                    'Environment & Conservation',
                ],
                'Social Studies' => [
                    'Kenya’s history',
                    'Physical features',
                    'Citizenship',
                    'Government & democracy',
                    'Maps & fieldwork',
                ],
                'Pre-Technical Studies' => [
                    'Basic drawing & design',
                    'Simple machines',
                    'Safety in workshops',
                    'Tools & materials',
                ],
                'Business Studies' => [
                    'Entrepreneurship',
                    'Trade',
                    'Money & banking',
                    'Record keeping',
                ],
                'ICT' => [
                    'Computer systems',
                    'Word processing',
                    'Internet & online safety',
                    'Digital citizenship',
                ],
                'Agriculture' => [
                    'Crop production',
                    'Animal production',
                    'Soil & water conservation',
                ],
                'Home Science' => [
                    'Nutrition',
                    'Food preparation',
                    'Clothing & textiles',
                    'Home management',
                ],
                'Creative Arts' => [
                    'Drawing & painting',
                    'Craftwork',
                    'Music theory',
                    'Performance',
                ],
                'Physical Education & Sports' => [
                    'Athletics',
                    'Ball games',
                    'Health & fitness',
                    'Safety in sports',
                ],
                'Religious Education' => [
                    'Holy scriptures',
                    'Worship',
                    'Moral values',
                    'Community life',
                ],
                'Life Skills' => [
                    'Self-awareness',
                    'Communication',
                    'Decision making',
                    'Relationships',
                ],
            ],
            8 => [
                'English' => [
                    'Advanced grammar',
                    'Writing (essays, reports)',
                    'Reading comprehension',
                    'Literature analysis',
                ],
                'Kiswahili' => [
                    'Insha',
                    'Ushairi',
                    'Sarufi',
                    'Fasihi andishi',
                    'Uandishi wa habari',
                ],
                'Mathematics' => [
                    'Algebra (linear equations)',
                    'Geometry (angles, polygons)',
                    'Probability',
                    'Statistics',
                    'Ratio & Proportion',
                ],
                'Integrated Science' => [
                    'Force & motion',
                    'Light & sound',
                    'Human health',
                    'Ecology',
                    'Simple technology',
                ],
                'Social Studies' => [
                    'East African history',
                    'Population studies',
                    'Resources & economic activities',
                    'Law & governance',
                ],
                'Pre-Technical Studies' => [
                    'Technical drawing',
                    'Electrical basics',
                    'Woodwork',
                    'Metalwork',
                    'Safety procedures',
                ],
                'Business Studies' => [
                    'Business plans',
                    'Marketing',
                    'Consumer protection',
                    'Banking services',
                ],
                'ICT' => [
                    'Spreadsheets',
                    'Databases',
                    'Online collaboration',
                    'Coding basics',
                ],
                'Agriculture' => [
                    'Crop improvement',
                    'Livestock management',
                    'Post-harvest practices',
                    'Agribusiness',
                ],
                'Home Science' => [
                    'Meal planning',
                    'Childcare',
                    'Health & hygiene',
                    'Interior design',
                ],
                'Creative Arts' => [
                    'Sculpture',
                    'Drama performance',
                    'Music composition',
                    'Dance',
                ],
                'Physical Education & Sports' => [
                    'Gymnastics',
                    'Athletics training',
                    'Team sports',
                    'Physical fitness',
                ],
                'Religious Education' => [
                    'Faith & worship',
                    'Leadership in religion',
                    'Interfaith relations',
                ],
                'Life Skills' => [
                    'Leadership',
                    'Conflict resolution',
                    'Stress management',
                    'Career awareness',
                ],
            ],
            9 => [
                'English' => [
                    'Public speaking',
                    'Writing (speeches, argumentative essays)',
                    'Poetry analysis',
                    'Literature (novels, drama)',
                ],
                'Kiswahili' => [
                    'Uandishi wa insha',
                    'Fasihi simulizi & andishi',
                    'Sarufi ya juu',
                    'Isimu',
                ],
                'Mathematics' => [
                    'Quadratic equations',
                    'Trigonometry',
                    'Mensuration',
                    'Probability & statistics',
                    'Graphs',
                ],
                'Integrated Science' => [
                    'Electricity & magnetism',
                    'Chemical reactions',
                    'Biotechnology',
                    'Health & environment',
                ],
                'Social Studies' => [
                    'African history',
                    'International relations',
                    'Global issues (climate change, trade)',
                    'Human rights',
                ],
                'Pre-Technical Studies' => [
                    'Engineering drawing',
                    'Mechanisms',
                    'Building technology',
                    'Applied electricity',
                ],
                'Business Studies' => [
                    'Accounting basics',
                    'Business law',
                    'Insurance',
                    'International trade',
                ],
                'ICT' => [
                    'Programming (Python/Java basics)',
                    'Web design',
                    'Cybersecurity',
                    'Emerging technologies',
                ],
                'Agriculture' => [
                    'Advanced crop science',
                    'Animal breeding',
                    'Sustainable farming',
                    'Agribusiness & marketing',
                ],
                'Home Science' => [
                    'Advanced nutrition',
                    'Consumer education',
                    'Hospitality',
                    'Family resource management',
                ],
                'Creative Arts' => [
                    'Theatre arts',
                    'Advanced music performance',
                    'Modern art & design',
                ],
                'Physical Education & Sports' => [
                    'Coaching skills',
                    'Sports science',
                    'Advanced fitness training',
                    'First aid',
                ],
                'Religious Education' => [
                    'Comparative religion',
                    'Ethics',
                    'Leadership roles',
                    'Spiritual growth',
                ],
                'Life Skills' => [
                    'Career planning',
                    'Entrepreneurship',
                    'Digital literacy',
                    'Emotional intelligence',
                ],
            ],
            10 => [
                'Visual Arts' => [
                    'Drawing',
                    'Painting',
                    'Sculpture',
                    'Art history',
                ],
                'Performing Arts' => [
                    'Drama',
                    'Dance',
                    'Music performance',
                    'Stage management',
                ],
                'Sports Science' => [
                    'Human anatomy',
                    'Training methods',
                    'Sports injuries',
                    'Nutrition in sports',
                ],
                'Media Arts' => [
                    'Photography',
                    'Film',
                    'Graphic design',
                    'Digital arts',
                ],
                'History' => [
                    'African civilizations',
                    'Colonialism',
                    'Nationalism',
                    'Modern world history',
                ],
                'Geography' => [
                    'Physical geography',
                    'Climate & environment',
                    'Human settlement',
                    'Natural resources',
                ],
                'Business Studies' => [
                    'Entrepreneurship',
                    'Marketing',
                    'Banking & finance',
                    'Accounting',
                ],
                'Religious Education' => [
                    'Comparative religion',
                    'Morality & ethics',
                    'Leadership in faith',
                    'Religion in society',
                ],
                'Life Skills Education' => [
                    'Leadership',
                    'Teamwork',
                    'Social responsibility',
                    'Conflict resolution',
                ],
                'Mathematics' => [
                    'Algebra',
                    'Trigonometry',
                    'Probability',
                    'Statistics',
                    'Functions',
                ],
                'Physics' => [
                    'Mechanics',
                    'Heat',
                    'Electricity',
                    'Waves',
                    'Modern physics',
                ],
                'Chemistry' => [
                    'Atomic structure',
                    'Acids & bases',
                    'Organic chemistry',
                    'Chemical bonding',
                ],
                'Biology' => [
                    'Cell biology',
                    'Ecology',
                    'Genetics',
                    'Human systems',
                ],
                'Computer Science' => [
                    'Programming (Python/Java)',
                    'Databases',
                    'Networking',
                    'Cybersecurity',
                ],
            ],
            11 => [
                'Visual Arts' => [
                    'Advanced drawing',
                    'Digital design',
                    'Sculpture techniques',
                ],
                'Performing Arts' => [
                    'Play production',
                    'Choreography',
                    'Music theory',
                    'Performance critique',
                ],
                'Sports Science' => [
                    'Sports psychology',
                    'Coaching methods',
                    'Advanced physiology',
                    'Injury prevention',
                ],
                'Media Arts' => [
                    'Video production',
                    'Editing',
                    'Animation',
                    'Graphic design',
                ],
                'History' => [
                    'African independence',
                    'World wars',
                    'Globalization',
                    'Pan-Africanism',
                ],
                'Geography' => [
                    'Geomorphology',
                    'Population studies',
                    'Economic activities',
                    'Sustainable development',
                ],
                'Business Studies' => [
                    'Financial management',
                    'Business law',
                    'Global trade',
                    'Insurance',
                ],
                'Religious Education' => [
                    'Ethics in society',
                    'Religious leadership',
                    'Interfaith relations',
                ],
                'Life Skills Education' => [
                    'Career planning',
                    'Community service',
                    'Problem solving',
                    'Stress management',
                ],
                'Mathematics' => [
                    'Calculus',
                    'Vectors',
                    'Complex numbers',
                    'Sequences & series',
                ],
                'Physics' => [
                    'Motion',
                    'Electricity & magnetism',
                    'Sound & waves',
                    'Nuclear physics',
                ],
                'Chemistry' => [
                    'Chemical kinetics',
                    'Electrochemistry',
                    'Organic compounds',
                ],
                'Biology' => [
                    'Evolution',
                    'Reproduction',
                    'Biotechnology',
                    'Human health',
                ],
                'Computer Science' => [
                    'Web development',
                    'Algorithms',
                    'Data structures',
                    'Cloud computing',
                ],
            ],
            12 => [
                'Visual Arts' => [
                    'Portfolio development',
                    'Contemporary art',
                    'Art exhibition',
                ],
                'Performing Arts' => [
                    'Advanced theatre',
                    'Music composition',
                    'Dance production',
                    'Arts management',
                ],
                'Sports Science' => [
                    'Sports management',
                    'Coaching certification',
                    'Advanced training',
                    'Sports ethics',
                ],
                'Media Arts' => [
                    'Film production',
                    'Broadcasting',
                    'Digital marketing',
                    'Media ethics',
                ],
                'History' => [
                    'African unity',
                    'Contemporary issues',
                    'Human rights',
                    'Global conflicts',
                ],
                'Geography' => [
                    'Environmental management',
                    'GIS & mapping',
                    'Climate change',
                    'Urbanization',
                ],
                'Business Studies' => [
                    'Entrepreneurship projects',
                    'E-commerce',
                    'International finance',
                    'Business strategy',
                ],
                'Religious Education' => [
                    'Peace studies',
                    'Religion & society',
                    'Advanced moral studies',
                ],
                'Life Skills Education' => [
                    'Entrepreneurship',
                    'Global citizenship',
                    'Personal development',
                ],
                'Mathematics' => [
                    'Advanced calculus',
                    'Probability distributions',
                    'Linear programming',
                    'Mathematical modeling',
                ],
                'Physics' => [
                    'Advanced mechanics',
                    'Optics',
                    'Quantum physics',
                    'Electronics',
                ],
                'Chemistry' => [
                    'Industrial chemistry',
                    'Analytical chemistry',
                    'Organic synthesis',
                    'Environmental chemistry',
                ],
                'Biology' => [
                    'Advanced genetics',
                    'Ecology',
                    'Biotechnology',
                    'Public health',
                ],
                'Computer Science' => [
                    'Artificial intelligence',
                    'Machine learning',
                    'Software development',
                    'Cybersecurity & ethics',
                ],
            ],
        ];

        // Create topics under subjects matched by name within each grade
        foreach ($topics as $gradeId => $subjectTopics) {
            foreach ($subjectTopics as $subjectName => $topicNames) {
                // Find subject by grade + subject name (handles both combined and split naming)
                $subject = DB::table('subjects')
                    ->where('grade_id', $gradeId)
                    ->where('name', $subjectName)
                    ->first();

                if (!$subject) {
                    // Try matching simplified variants for combined labels
                    $altNames = [
                        // Grade 4–6 combined creative arts label
                        'Creative Arts' => [
                            'Creative Arts', 'Creative Arts (Art, Craft, Music)', 'Art & Craft', 'Music',
                        ],
                        // Grade 1 language umbrella
                        'Language Activities' => [
                            'Language Activities (English, Kiswahili, Indigenous)', 'English', 'Kiswahili', 'Indigenous Languages', 'Indigenous',
                        ],
                        'Movement & Creative Activities' => [
                            'Movement & Creative Activities', 'Movement & Physical Activities', 'Physical & Health Education',
                        ],
                    ];

                    $tryNames = $altNames[$subjectName] ?? [$subjectName];

                    $subject = DB::table('subjects')
                        ->where('grade_id', $gradeId)
                        ->whereIn('name', $tryNames)
                        ->first();
                }

                if (!$subject) {
                    continue; // Skip if subject not present
                }

                foreach ($topicNames as $topicName) {
                    DB::table('topics')->updateOrInsert(
                        ['subject_id' => $subject->id, 'name' => $topicName],
                        [
                            'description' => null,
                            'is_approved' => true,
                            'approval_requested_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                            'image' => null,
                            'slug' => \Illuminate\Support\Str::slug($topicName),
                        ]
                    );
                }
            }
        }
    }
}