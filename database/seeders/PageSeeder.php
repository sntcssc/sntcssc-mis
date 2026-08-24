<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\PageTranslation;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PageSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the core institutional pages with multilingual content (en, hi, bn).
     */
    public function run(): void
    {
        $pages = $this->pagesData();

        foreach ($pages as $index => $pageData) {
            DB::transaction(function () use ($pageData, $index) {
                $page = Page::withTrashed()->firstOrNew(['slug' => $pageData['slug']]);

                $page->status = Page::STATUS_PUBLISHED;
                $page->sort_order = $pageData['sort_order'] ?? ($index + 1);
                $page->is_system = true;
                $page->save();

                if ($page->trashed()) {
                    $page->restore();
                }

                foreach ($pageData['translations'] as $locale => $trans) {
                    $translation = PageTranslation::withTrashed()->firstOrNew([
                        'page_id' => $page->id,
                        'locale' => $locale,
                    ]);

                    $translation->title = $trans['title'];
                    $translation->meta_title = $trans['meta_title'] ?? $trans['title'];
                    $translation->meta_description = $trans['meta_description'] ?? null;
                    $translation->meta_keywords = $trans['meta_keywords'] ?? null;
                    $translation->content = $trans['content'];
                    $translation->save();

                    if ($translation->trashed()) {
                        $translation->restore();
                    }
                }
            });
        }
    }

    /**
     * @return array<int, array{slug: string, sort_order: int, translations: array<string, array<string, string>>}>
     */
    protected function pagesData(): array
    {
        return [
            /* 1. About Us */
            [
                'slug' => 'about-us',
                'sort_order' => 1,
                'translations' => [
                    'en' => [
                        'title' => 'About Us',
                        'meta_title' => 'About Us — Satyendranath Tagore Civil Services Study Centre',
                        'meta_description' => 'Learn about Satyendranath Tagore Civil Services Study Centre (SNT CSSC), our mission, state-of-the-art coaching facilities, and dedication to mentoring civil service aspirants.',
                        'meta_keywords' => 'about snt cssc, civil services coaching, upsc west bengal, upsc mentorship, sntcssc mis',
                        'content' => '<h2>Welcome to Satyendranath Tagore Civil Services Study Centre</h2>
<p>The <strong>Satyendranath Tagore Civil Services Study Centre (SNTCSSC)</strong> was established with the vision of nurturing and mentoring promising candidates from West Bengal and across India to achieve distinction in the UPSC Civil Services Examination and state civil services.</p>

<h3>Our Core Mission</h3>
<p>Our mission is to democratize quality civil services preparation through structured classroom programs, comprehensive test series, personalized answer evaluation, and mentorship from veteran bureaucrats, academicians, and subject matter experts.</p>

<h3>What We Offer</h3>
<ul>
    <li><strong>Expert Faculty & Bureaucratic Mentorship:</strong> Direct guidance from serving and retired IAS, IPS, and WBCS officers alongside distinguished academicians.</li>
    <li><strong>Modern Digital Infrastructure:</strong> Fully integrated Management Information System (MIS), computer labs, digital library access, and performance analytics.</li>
    <li><strong>Rigorous Evaluation Framework:</strong> Weekly Prelims mock drills, Mains descriptive answer reviews, and simulated personality test interview boards.</li>
    <li><strong>Inclusive & Accessible Education:</strong> Subsidized fee structures and scholarship opportunities for meritorious students from all socio-economic backgrounds.</li>
</ul>

<h3>Governance & Academic Excellence</h3>
<p>SNTCSSC operates with high institutional governance standards, ensuring transparency in admissions, automated attendance tracking, detailed candidate dossiers, and continuous curriculum updates aligned with changing UPSC patterns.</p>',
                    ],
                    'hi' => [
                        'title' => 'हमारे बारे में',
                        'meta_title' => 'हमारे बारे में — सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र',
                        'meta_description' => 'सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र (SNTCSSC) के उद्देश्य, उच्च स्तरीय कोचिंग सुविधाओं और सिविल सेवा अभ्यर्थियों के मार्गदर्शन के बारे में जानें।',
                        'meta_keywords' => 'SNTCSSC के बारे में, यूपीएससी कोचिंग, सिविल सेवा तैयारी, सत्येंद्रनाथ टैगोर केंद्र',
                        'content' => '<h2>सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र में आपका स्वागत है</h2>
<p><strong>सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र (SNTCSSC)</strong> की स्थापना पश्चिम बंगाल एवं सम्पूर्ण भारत के प्रतिभावान अभ्यर्थियों को संघ लोक सेवा आयोग (UPSC) सिविल सेवा परीक्षा एवं राज्य प्रशासनिक सेवाओं में सफलता हेतु मार्गदर्शन प्रदान करने के लिए की गई है।</p>

<h3>हमारा मुख्य उद्देश्य</h3>
<p>हमारा उद्देश्य उच्च स्तरीय एवं सुनियोजित शैक्षणिक मार्गदर्शन, गुणवत्तापूर्ण अध्ययन सामग्री, और अनुभवी प्रशासकों व प्राध्यापकों के सान्निध्य में उत्कृष्ट तैयारी सुनिश्चित करना है।</p>

<h3>हमारी मुख्य विशेषताएँ</h3>
<ul>
    <li><strong>विशेषज्ञ संकाय एवं प्रशासनिक मार्गदर्शन:</strong> कार्यरत एवं सेवानिवृत्त आईएएस, आईपीएस अधिकारियों और विषय विशेषज्ञों द्वारा नियमित मार्गदर्शन।</li>
    <li><strong>आधुनिक डिजिटल ढांचा:</strong> एकीकृत प्रबंधन सूचना प्रणाली (MIS), डिजिटल लाइब्रेरी एवं व्यक्तिगत प्रदर्शन विश्लेषण।</li>
    <li><strong>नियमित मूल्यांकन प्रणाली:</strong> साप्ताहिक प्रारंभिक मॉक टेस्ट, मुख्य परीक्षा उत्तर लेखन मूल्यांकन एवं साक्षात्कार मार्गदर्शन।</li>
    <li><strong>समावेशी एवं सुलभ शिक्षा:</strong> मेधावी अभ्यर्थियों के लिए रियायती शुल्क एवं छात्रवृत्ति की सुविधा।</li>
</ul>',
                    ],
                    'bn' => [
                        'title' => 'আমাদের সম্পর্কে',
                        'meta_title' => 'আমাদের সম্পর্কে — সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার',
                        'meta_description' => 'সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার (SNTCSSC)-এর লক্ষ্য, দৃষ্টিভঙ্গি এবং সিভিল সার্ভিস প্রার্থীদের জন্য আধুনিক প্রশিক্ষণ ব্যবস্থা সম্পর্কে জানুন।',
                        'meta_keywords' => 'আমাদের সম্পর্কে, SNTCSSC, ইউপিএসসি কোচিং, সিভিল সার্ভিস প্রস্তুতি, পশ্চিমবঙ্গ',
                        'content' => '<h2>সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টারে আপনাকে স্বাগতম</h2>
<p><strong>সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার (SNTCSSC)</strong> পশ্চিমবঙ্গ তথা সমগ্র ভারতের সিভিল সার্ভিস পরীক্ষার্থীদের ইউপিএসসি ও রাজ্য প্রশাসনিক পরীক্ষার জন্য উচ্চমানের প্রস্তুতি ও দিকনির্দেশনা প্রদানের লক্ষ্যে প্রতিষ্ঠিত একটি শীর্ষস্থানীয় প্রতিষ্ঠান।</p>

<h3>আমাদের মূল লক্ষ্য</h3>
<p>আমাদের উদ্দেশ্য হল সুসংগঠিত ক্লাসরুম প্রশিক্ষণ, অভিজ্ঞ আমলা ও শিক্ষাবিদদের তত্ত্বাবধান, এবং আধুনিক ডিজিটাল ব্যবস্থার মাধ্যমে শিক্ষার্থীদের সিভিল সার্ভিসের কঠিন যাত্রায় সফল করে তোলা।</p>

<h3>আমাদের প্রধান সুবিধাসমূহ</h3>
<ul>
    <li><strong>অভিজ্ঞ অনুষদ ও প্রশাসনিক পরামর্শদাতা:</strong> প্রাক্তন ও কর্মরত আইএএস, আইপিএস এবং শিক্ষাবিদদের প্রত্যক্ষ তত্ত্বাবধান।</li>
    <li><strong>আধুনিক ডিজিটাল এমআইএস পোর্টাল:</strong> অনলাইন ভর্তি, উপস্থিতি ট্র্যাকিং, টেস্ট সিরিজ রেজাল্ট এবং ডিজিটাল লাইব্রেরি।</li>
    <li><strong>নিয়মিত টেস্ট সিরিজ ও মূল্যায়ন:</strong> প্রিলিমিনারি ও মেইনস পরীক্ষার জন্য নিয়মিত মক টেস্ট এবং খাতা মূল্যায়ন।</li>
    <li><strong>সাশ্রয়ী ও অন্তর্ভুক্তিমূলক শিক্ষা:</strong> মেধাবী ছাত্রছাত্রীদের জন্য স্কলারশিপ এবং সুলভ শিক্ষাদান ব্যবস্থা।</li>
</ul>',
                    ],
                ],
            ],

            /* 2. Privacy Policy */
            [
                'slug' => 'privacy-policy',
                'sort_order' => 2,
                'translations' => [
                    'en' => [
                        'title' => 'Privacy Policy',
                        'meta_title' => 'Privacy Policy — SNT CSSC Management Information System',
                        'meta_description' => 'Privacy Policy of SNT CSSC MIS explaining data collection, protection, cookies, and user rights.',
                        'meta_keywords' => 'privacy policy, sntcssc privacy, data protection, student data privacy',
                        'content' => '<h2>Privacy Policy</h2>
<p class="text-sm text-muted-foreground">Last updated: August 2026</p>

<p>Satyendranath Tagore Civil Services Study Centre (SNTCSSC) is dedicated to protecting the privacy and security of all candidates, staff, and visitors who access our portal and services.</p>

<h3>1. Information We Collect</h3>
<ul>
    <li><strong>Personal Information:</strong> Full name, date of birth, gender, category, residential address, mobile phone number, WhatsApp contact, and email address.</li>
    <li><strong>Academic & Identity Records:</strong> Educational qualifications, marksheets, caste/category certificates, photograph, and identity proof documents during admission.</li>
    <li><strong>System Logs & Analytics:</strong> IP address, device specifications, browser type, login timestamp, and audit trail events.</li>
</ul>

<h3>2. How We Use Your Information</h3>
<p>Collected information is used exclusively for:</p>
<ul>
    <li>Processing admission applications, screening tests, and enrollment verifications.</li>
    <li>Issuing admit cards, student ID cards, fee receipts, and test performance reports.</li>
    <li>Dispatching official communications, lecture schedules, OTP verifications, and system alerts via SMS/Email.</li>
    <li>Maintaining statutory compliance, audit integrity, and institutional safety.</li>
</ul>

<h3>3. Data Protection & Security</h3>
<p>All sensitive records and credentials are encrypted at rest using industry-standard cryptography. Access to personal candidate data is restricted strictly to authorized institutional officials under strict role-based access control (RBAC).</p>

<h3>4. Third-Party Sharing</h3>
<p>We do <strong>not</strong> sell, rent, or trade your personal information. Data may only be shared with authorized payment gateway partners (e.g., Razorpay/PhonePe) for secure transaction processing or when required by statutory regulatory bodies.</p>

<h3>5. Contact Us Regarding Privacy</h3>
<p>For any queries or grievances regarding data privacy, contact our Data Protection Officer at <code>privacy@sntcssc.in</code>.</p>',
                    ],
                    'hi' => [
                        'title' => 'गोपनीयता नीति',
                        'meta_title' => 'गोपनीयता नीति — सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र',
                        'meta_description' => 'SNTCSSC पोर्टल की गोपनीयता नीति और डेटा सुरक्षा सम्बन्धी नियम।',
                        'meta_keywords' => 'गोपनीयता नीति, डेटा सुरक्षा, SNTCSSC privacy policy',
                        'content' => '<h2>गोपनीयता नीति (Privacy Policy)</h2>
<p>सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र (SNTCSSC) अपने सभी छात्रों एवं पोर्टल उपयोगकर्ताओं की व्यक्तिगत जानकारी की सुरक्षा एवं गोपनीयता बनाए रखने के लिए प्रतिबद्ध है।</p>

<h3>1. एकत्रित की जाने वाली जानकारी</h3>
<p>प्रवेश प्रक्रिया एवं पोर्टल उपयोग के दौरान हम नाम, ईमेल, मोबाइल नंबर, शैक्षणिक योग्यता एवं पहचान प्रमाण पत्र जैसी आवश्यक जानकारी एकत्र करते हैं।</p>

<h3>2. जानकारी का उपयोग</h3>
<p>एकत्रित डेटा का उपयोग केवल प्रवेश सत्यापन, परीक्षा आयोजन, प्रवेश पत्र वितरण एवं महत्वपूर्ण सूचनाएँ प्रेषित करने हेतु किया जाता है।</p>

<h3>3. डेटा सुरक्षा</h3>
<p>हमारा सिस्टम आधुनिक एन्क्रिप्शन तकनीकों द्वारा संरक्षित है एवं अनाधिकृत पहुंच को रोकने हेतु कठोर सुरक्षा उपायों का पालन किया जाता है।</p>',
                    ],
                    'bn' => [
                        'title' => 'গোপনীয়তা নীতি',
                        'meta_title' => 'গোপনীয়তা নীতি — SNTCSSC MIS',
                        'meta_description' => 'SNTCSSC পোর্টালের গোপনীয়তা নীতি এবং ডেটা সুরক্ষা সংক্রান্ত নির্দেশাবলী।',
                        'meta_keywords' => 'গোপনীয়তা নীতি, ডেটা সুরক্ষা, SNTCSSC privacy policy',
                        'content' => '<h2>গোপনীয়তা নীতি (Privacy Policy)</h2>
<p>সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার শিক্ষার্থীদের সমস্ত ব্যক্তিগত তথ্যের নিরাপত্তা ও গোপনীয়তা বজায় রাখতে দৃঢ় প্রতিশ্রুতিবদ্ধ।</p>

<h3>১. সংগৃহীত তথ্যাবলী</h3>
<p>ভর্তি প্রক্রিয়া, পরীক্ষা ও যোগাযোগের জন্য আমরা নাম, ঠিকানা, ইমেইল, ফোন নম্বর এবং শিক্ষাগত যোগ্যতার প্রমাণাদি সংগ্রহ করি।</p>

<h3>২. তথ্যের ব্যবহার</h3>
<p>এই তথ্যগুলি কেবলমাত্র প্রাতিষ্ঠানিক কার্যাবলী, পরীক্ষার নোটিশ, অ্যাডমিট কার্ড প্রদান ও অভ্যন্তরীণ রেকর্ডের জন্য ব্যবহৃত হয়।</p>

<h3>৩. তথ্যের নিরাপত্তা</h3>
<p>শিক্ষার্থীদের সমস্ত ডেটা আধুনিক এনক্রিপশন প্রযুক্তির মাধ্যমে সুরক্ষিত থাকে এবং কোনও তৃতীয় পক্ষের সাথে বাণিজ্যিক উদ্দেশ্যে শেয়ার করা হয় না।</p>',
                    ],
                ],
            ],

            /* 3. Terms and Conditions */
            [
                'slug' => 'terms-and-conditions',
                'sort_order' => 3,
                'translations' => [
                    'en' => [
                        'title' => 'Terms and Conditions',
                        'meta_title' => 'Terms and Conditions — SNTCSSC Academic MIS',
                        'meta_description' => 'Terms and Conditions governing the use of SNTCSSC MIS portal, courses, admissions, and campus rules.',
                        'meta_keywords' => 'terms and conditions, sntcssc terms, student guidelines, code of conduct',
                        'content' => '<h2>Terms and Conditions of Use</h2>
<p class="text-sm text-muted-foreground">Effective Date: August 2026</p>

<h3>1. Acceptance of Terms</h3>
<p>By accessing this Management Information System (MIS) or enrolling in any academic program of Satyendranath Tagore Civil Services Study Centre, you agree to be bound by these Terms and Conditions and all applicable regulations.</p>

<h3>2. Student Account & Credential Responsibility</h3>
<ul>
    <li>Candidates are responsible for maintaining the strict confidentiality of their portal credentials, OTPs, and passkeys.</li>
    <li>Sharing credentials or permitting unauthorized access to proprietary study materials and test series will lead to immediate cancellation of admission and portal access.</li>
</ul>

<h3>3. Academic Code of Conduct</h3>
<ul>
    <li>Strict academic integrity is required during all mock examinations and evaluation tests. Malpractice or plagiarism will result in disqualification.</li>
    <li>Minimum 75% classroom attendance is mandatory to remain eligible for sponsored test series and mock interview panels.</li>
    <li>Disciplinary decorum must be maintained across physical campus premises as well as digital discussion forums.</li>
</ul>

<h3>4. Fee Payment & Installments</h3>
<p>All program fees must be cleared within designated due dates. Failure to settle installments may result in temporary suspension of digital batch access and study material distribution.</p>

<h3>5. Governing Law & Jurisdiction</h3>
<p>These terms shall be governed by and construed in accordance with the laws of the State of West Bengal, India. Any disputes arising hereunder shall be subject to the exclusive jurisdiction of the competent courts in Kolkata.</p>',
                    ],
                    'hi' => [
                        'title' => 'नियम एवं शर्तें',
                        'meta_title' => 'नियम एवं शर्तें — सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र',
                        'meta_description' => 'SNTCSSC पोर्टल और अध्ययन कार्यक्रमों के उपयोग सम्बन्धी नियम एवं शर्तें।',
                        'meta_keywords' => 'नियम और शर्तें, sntcssc terms and conditions',
                        'content' => '<h2>नियम एवं शर्तें (Terms and Conditions)</h2>
<p>SNTCSSC पोर्टल का उपयोग करने एवं हमारे किसी भी पाठ्यक्रम में प्रवेश लेने पर आप निम्नलिखित नियमों एवं शर्तों से बाध्य होंगे।</p>

<h3>१. छात्र आचार संहिता</h3>
<ul>
    <li>सभी विद्यार्थियों को अध्ययन एवं परीक्षाओं के दौरान अनुशासन का पालन करना अनिवार्य है।</li>
    <li>पोर्टल लॉगिन पासवर्ड या ओटीपी किसी अन्य व्यक्ति के साथ साझा करना पूर्णतः वर्जित है।</li>
    <li>कक्षाओं में न्यूनतम उपस्थिति प्रतिशत बनाए रखना आवश्यक है।</li>
</ul>

<h3>२. शुल्क भुगतान</h3>
<p>निर्धारित तिथि तक पाठ्यक्रम शुल्क का भुगतान अनिवार्य है।</p>',
                    ],
                    'bn' => [
                        'title' => 'নিয়ম ও শর্তাবলী',
                        'meta_title' => 'নিয়ম ও শর্তাবলী — SNTCSSC MIS',
                        'meta_description' => 'SNTCSSC পোর্টাল এবং একাডেমিক কোর্স ব্যবহারের নিয়ম ও শর্তাবলী।',
                        'meta_keywords' => 'নিয়ম ও শর্তাবলী, terms and conditions sntcssc',
                        'content' => '<h2>নিয়ম ও শর্তাবলী (Terms and Conditions)</h2>
<p>এই পোর্টাল ব্যবহার এবং SNTCSSC-এর যেকোনো কোর্সে ভর্তির ক্ষেত্রে নিচের নিয়মাবলী প্রযোজ্য হবে।</p>

<h3>১. ছাত্র আচরণবিধি ও শৃঙ্খলা</h3>
<ul>
    <li>পরীক্ষা এবং ক্লাসরুমে পূর্ণ শৃঙ্খলা বজায় রাখা আবশ্যিক।</li>
    <li>ব্যক্তিগত আইডি এবং পাসওয়ার্ড অন্য কারও সাথে আদান-প্রদান করা সম্পূর্ণ নিষিদ্ধ।</li>
    <li>ক্লাসে নিয়মিত উপস্থিতি বজায় রাখা বাধ্যতামূলক।</li>
</ul>

<h3>২. আইনি এখতিয়ার</h3>
<p>যেকোনো প্রকার আইনি বিরোধের ক্ষেত্রে কলকাতার উপযুক্ত আদালতের বিচারিক এখতিয়ার প্রযোজ্য হবে।</p>',
                    ],
                ],
            ],

            /* 4. Refund and Cancellation Policy */
            [
                'slug' => 'refund-and-cancellation-policy',
                'sort_order' => 4,
                'translations' => [
                    'en' => [
                        'title' => 'Refund and Cancellation Policy',
                        'meta_title' => 'Refund & Cancellation Policy — SNT CSSC',
                        'meta_description' => 'Refund and cancellation policy guidelines for course admissions, examination fees, and registration charges.',
                        'meta_keywords' => 'refund policy, cancellation policy, fee refund sntcssc, admission cancellation',
                        'content' => '<h2>Refund and Cancellation Policy</h2>

<p>Satyendranath Tagore Civil Services Study Centre maintains a clear and transparent policy regarding fee refunds, withdrawals, and course cancellations.</p>

<h3>1. Admission & Registration Fees</h3>
<ul>
    <li>The initial Registration / Application Processing fee is <strong>strictly non-refundable</strong> under all circumstances as it covers administrative processing and entrance test overheads.</li>
    <li>Admission fees for finalized selection lists are subject to the schedule detailed below.</li>
</ul>

<h3>2. Course Fee Refund Schedule</h3>
<table>
    <thead>
        <tr>
            <th>Time of Cancellation Request</th>
            <th>Refund Eligibility</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Written request received 7 or more days prior to batch commencement</td>
            <td>80% of Tuition Fee refunded (20% administrative fee retained)</td>
        </tr>
        <tr>
            <td>Written request received within 7 days before batch commencement</td>
            <td>50% of Tuition Fee refunded</td>
        </tr>
        <tr>
            <td>Request received after batch commencement date</td>
            <td>No refund admissible</td>
        </tr>
    </tbody>
</table>

<h3>3. Mode and Timeline of Refund</h3>
<p>All approved refunds will be credited back to the original payment source (bank account / card / UPI) within <strong>10 to 14 working days</strong> from the formal approval of the cancellation request.</p>

<h3>4. Processing Cancellation Requests</h3>
<p>Candidates must submit their refund requests in writing to the Accounts Section via email at <code>accounts@sntcssc.in</code> along with the transaction reference, admission number, and valid identification proof.</p>',
                    ],
                    'hi' => [
                        'title' => 'रिफंड एवं रद्दीकरण नीति',
                        'meta_title' => 'रिफंड एवं रद्दीकरण नीति — सत्येंद्रनाथ टैगोर केंद्र',
                        'meta_description' => 'प्रवेश शुल्क रद्दीकरण एवं रिफंड सम्बन्धी दिशा-निर्देश।',
                        'meta_keywords' => 'रिफंड नीति, शुल्क वापसी, refund policy sntcssc',
                        'content' => '<h2>रिफंड एवं रद्दीकरण नीति (Refund & Cancellation)</h2>
<p>प्रवेश रद्दीकरण एवं शुल्क वापसी के संबंध में संस्थान के नियम निम्नलिखित हैं:</p>

<ul>
    <li><strong>आवेदन शुल्क:</strong> प्रवेश परीक्षा एवं पंजीकरण शुल्क गैर-वापसी योग्य (Non-refundable) है।</li>
    <li><strong>पाठ्यक्रम शुल्क:</strong> बैच प्रारंभ होने से ७ दिन पूर्व लिखित आवेदन देने पर प्रशासनिक कटौती के पश्चात आंशिक शुल्क वापस किया जा सकता है।</li>
    <li><strong>बैच प्रारंभ के बाद:</strong> कक्षाएं प्रारंभ होने के पश्चात कोई रिफंड स्वीकार्य नहीं होगा।</li>
</ul>',
                    ],
                    'bn' => [
                        'title' => 'রিফান্ড এবং বাতিলকরণ নীতি',
                        'meta_title' => 'রিফান্ড এবং বাতিলকরণ নীতি — SNTCSSC',
                        'meta_description' => 'ভর্তি ফি বাতিল ও রিফান্ড সংক্রান্ত নির্দেশিকা।',
                        'meta_keywords' => 'রিফান্ড পলিসি, ফি ফেরত, sntcssc refund policy',
                        'content' => '<h2>রিফান্ড এবং বাতিলকরণ নীতি (Refund Policy)</h2>
<p>কোর্স বাতিল এবং ফি ফেরতের ক্ষেত্রে নিম্নোক্ত নিয়মসমূহ প্রযোজ্য:</p>

<ul>
    <li><strong>রেজিস্ট্রেশন ফি:</strong> আবেদন ও রেজিস্ট্রেশন ফি কোনো অবস্থাতেই অফেরতযোগ্য।</li>
    <li><strong>কোর্স ফি ফেরত:</strong> ব্যাচ শুরু হওয়ার ৭ দিন পূর্বে লিখিত আবেদন জানালে প্রশাসনিক খরচ বাদে ফি ফেরত বিবেচনা করা হবে।</li>
    <li><strong>ক্লাস শুরুর পর:</strong> ব্যাচের ক্লাস শুরু হওয়ার পর কোনো প্রকার ফি ফেরত দেওয়া হবে না।</li>
</ul>',
                    ],
                ],
            ],

            /* 5. Legal Disclaimer */
            [
                'slug' => 'legal-disclaimer',
                'sort_order' => 5,
                'translations' => [
                    'en' => [
                        'title' => 'Legal Disclaimer',
                        'meta_title' => 'Legal Disclaimer — Satyendranath Tagore Civil Services Study Centre',
                        'meta_description' => 'Legal disclaimer regarding information accuracy, external links, third-party content, and educational coaching warranties.',
                        'meta_keywords' => 'legal disclaimer, sntcssc disclaimer, academic disclaimer',
                        'content' => '<h2>Legal Disclaimer</h2>

<p>The information, study materials, mock question papers, and guidance provided on this portal and in our coaching programs are intended strictly for educational and training purposes for competitive examinations.</p>

<h3>1. No Guarantee of Selection</h3>
<p>While Satyendranath Tagore Civil Services Study Centre strives to provide the highest standard of academic mentoring, coaching, and evaluation, we make <strong>no representation or guarantee</strong> that enrollment in our programs will ensure selection or qualification in the UPSC Civil Services Examination or any competitive examination.</p>

<h3>2. Accuracy of Content</h3>
<p>Every reasonable effort is made to maintain accurate and up-to-date syllabus guides, examination notifications, and study resources. However, official guidelines, eligibility norms, and cut-off criteria published by the Union Public Service Commission (UPSC) or respective State Public Service Commissions shall always supersede any institutional material.</p>

<h3>3. System Availability</h3>
<p>SNTCSSC makes every effort to keep the MIS portal operational 24/7. However, we take no responsibility for temporary portal unavailability resulting from scheduled technical maintenance, telecommunication failures, or server disruptions beyond our reasonable control.</p>',
                    ],
                    'hi' => [
                        'title' => 'कानूनी अस्वीकरण',
                        'meta_title' => 'कानूनी अस्वीकरण — SNTCSSC',
                        'meta_description' => 'संस्थान की अध्ययन सामग्री एवं चयन सम्बन्धी कानूनी अस्वीकरण।',
                        'meta_keywords' => 'कानूनी अस्वीकरण, legal disclaimer sntcssc',
                        'content' => '<h2>कानूनी अस्वीकरण (Legal Disclaimer)</h2>
<p>इस पोर्टल पर उपलब्ध अध्ययन सामग्री और मार्गदर्शन केवल प्रतियोगी परीक्षाओं की तैयारी एवं शैक्षणिक उद्देश्य हेतु है।</p>
<ul>
    <li>संस्थान में प्रवेश किसी भी सरकारी सेवा या यूपीएससी परीक्षा में अंतिम चयन की गारंटी नहीं देता है।</li>
    <li>आधिकारिक सूचनाओं के लिए संघ लोक सेवा आयोग (UPSC) की आधिकारिक अधिसूचना ही सर्वमान्य होगी।</li>
</ul>',
                    ],
                    'bn' => [
                        'title' => 'আইনি দাবিত্যাগ',
                        'meta_title' => 'আইনি দাবিত্যাগ — SNTCSSC',
                        'meta_description' => 'শিক্ষামূলক তথ্য ও পরীক্ষা সংক্রান্ত আইনি দাবিত্যাগ।',
                        'meta_keywords' => 'আইনি দাবিত্যাগ, disclaimer sntcssc',
                        'content' => '<h2>আইনি দাবিত্যাগ (Legal Disclaimer)</h2>
<p>এই পোর্টালে প্রদত্ত সমস্ত তথ্য এবং স্টাডি মেটেরিয়াল কেবল শিক্ষার্থীদের শিক্ষামূলক প্রস্তুতির উদ্দেশ্যে প্রদান করা হয়েছে।</p>
<ul>
    <li>আমাদের কোনো কোর্সে ভর্তি ইউপিএসসি বা সরকারি চাকরিতে চূড়ান্ত নির্বাচনের কোনো নিশ্চয়তা দেয় না।</li>
    <li>পরীক্ষার নিয়মাবলীর জন্য ইউপিএসসি-র অফিশিয়াল বিজ্ঞপ্তিই চূড়ান্ত বলে গণ্য হবে।</li>
</ul>',
                    ],
                ],
            ],

            /* 6. Copyright Policy */
            [
                'slug' => 'copyright-policy',
                'sort_order' => 6,
                'translations' => [
                    'en' => [
                        'title' => 'Copyright Policy',
                        'meta_title' => 'Copyright Policy — SNT CSSC All Rights Reserved',
                        'meta_description' => 'Intellectual property rights and copyright policy governing SNT CSSC course modules, test papers, and digital assets.',
                        'meta_keywords' => 'copyright policy, intellectual property, sntcssc copyright',
                        'content' => '<h2>Copyright & Intellectual Property Policy</h2>

<p>All contents published on this portal — including but not limited to text, curriculum designs, test series question banks, model answer booklets, evaluation rubrics, graphics, icons, logos, audio/video lectures, software code, and interface styling — are the exclusive intellectual property of <strong>Satyendranath Tagore Civil Services Study Centre</strong>, unless explicitly credited to external third-party sources.</p>

<h3>Permitted Use</h3>
<ul>
    <li>Enrolled students are granted a limited, personal, non-exclusive, non-transferable license to access and view materials solely for their own individual preparation.</li>
    <li>Single copies of designated PDF notes may be downloaded for personal offline revision.</li>
</ul>

<h3>Prohibited Acts</h3>
<ul>
    <li>Commercial reproduction, redistribution, syndication, or re-publishing of any question bank or study module is strictly prohibited.</li>
    <li>Recording, screen-capturing, or uploading faculty lectures to external video sharing platforms (YouTube, Telegram, Torrent, etc.) constitutes an infringement punishable under the Indian Copyright Act, 1957.</li>
</ul>

<p>For licensing or permission queries, contact: <code>copyright@sntcssc.in</code>.</p>',
                    ],
                    'hi' => [
                        'title' => 'कॉपीराइट नीति',
                        'meta_title' => 'कॉपीराइट नीति — सत्येंद्रनाथ टैगोर केंद्र',
                        'meta_description' => 'बौद्धिक संपदा अधिकार एवं अध्ययन सामग्री की कॉपीराइट नीति।',
                        'meta_keywords' => 'कॉपीराइट नीति, copyright policy sntcssc',
                        'content' => '<h2>कॉपीराइट नीति (Copyright Policy)</h2>
<p>इस पोर्टल पर उपलब्ध सभी अध्ययन सामग्री, टेस्ट सीरीज एवं वीडियो व्याख्यान संस्थान की बौद्धिक संपदा हैं।</p>
<p>सामग्री का किसी भी प्रकार से वाणिज्यिक पुनरुत्पादन, टेलीग्राम या सोशल मीडिया पर अवैध वितरण कानूनी अपराध है।</p>',
                    ],
                    'bn' => [
                        'title' => 'কপিরাইট নীতি',
                        'meta_title' => 'কপিরাইট নীতি — SNTCSSC',
                        'meta_description' => 'SNTCSSC-এর স্টাডি মেটেরিয়াল ও ডিজিটাল কনটেন্টের কপিরাইট নির্দেশিকা।',
                        'meta_keywords' => 'কপিরাইট নীতি, copyright policy sntcssc',
                        'content' => '<h2>কপিরাইট নীতি (Copyright Policy)</h2>
<p>এই ওয়েবসাইটের সমস্ত কনটেন্ট, লেকচার নোটস এবং টেস্ট সিরিজ সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টারের নিজস্ব সম্পত্তি।</p>
<p>অনুমতি ছাড়া এই সামগ্রী কোনো সোশ্যাল মিডিয়া বা অন্য কোথাও বাণিজ্যিক উদ্দেশ্যে শেয়ার করা আইনত দণ্ডনীয়।</p>',
                    ],
                ],
            ],

            /* 7. Hyperlink Policy */
            [
                'slug' => 'hyperlink-policy',
                'sort_order' => 7,
                'translations' => [
                    'en' => [
                        'title' => 'Hyperlink Policy',
                        'meta_title' => 'Hyperlink Policy — Satyendranath Tagore Civil Services Study Centre',
                        'meta_description' => 'Guidelines on linking to the SNTCSSC portal and external websites linked within our services.',
                        'meta_keywords' => 'hyperlink policy, website linking policy, sntcssc links',
                        'content' => '<h2>Hyperlink Policy</h2>

<h3>1. Links to External Websites</h3>
<p>At many places in this portal, you will find links to other government and academic websites (e.g., UPSC Official Portal, Department of Personnel & Training, West Bengal Public Service Commission). These links are provided solely for convenience and research reference.</p>
<p>SNTCSSC is not responsible for the contents or reliability of linked external websites and does not necessarily endorse the views expressed within them.</p>

<h3>2. Linking to SNT CSSC MIS Portal</h3>
<p>We welcome links to information hosted on this portal from reputable educational and government organizations, subject to the following conditions:</p>
<ul>
    <li>Direct linking to our homepage and public policy pages is permitted without prior explicit authorization.</li>
    <li>Our web pages must load into an entire newly opened browser window rather than within frames on the referring site.</li>
    <li>Linking must not imply any sponsorship, endorsement, or commercial affiliation without written consent.</li>
</ul>',
                    ],
                    'hi' => [
                        'title' => 'हाइपरलिंक नीति',
                        'meta_title' => 'हाइपरलिंक नीति — SNTCSSC',
                        'meta_description' => 'पोर्टल लिंक करने एवं बाहरी वेबसाइटों के संदर्भ सम्बन्धी नीति।',
                        'meta_keywords' => 'हाइपरलिंक नीति, hyperlink policy sntcssc',
                        'content' => '<h2>हाइपरलिंक नीति (Hyperlink Policy)</h2>
<p>इस पोर्टल पर सरकारी एवं शैक्षणिक संदर्भ हेतु बाहरी वेबसाइटों के लिंक प्रदान किए गए हैं। संस्थान बाहरी वेबसाइटों की सामग्री के लिए उत्तरदायी नहीं है।</p>
<p>शैक्षणिक संस्थान हमारे पोर्टल के मुख्य पृष्ठ का लिंक अपनी वेबसाइट पर स्वतंत्र रूप से साझा कर सकते हैं।</p>',
                    ],
                    'bn' => [
                        'title' => 'হাইপারলিংক নীতি',
                        'meta_title' => 'হাইপারলিংক নীতি — SNTCSSC',
                        'meta_description' => 'বহিরাগত লিঙ্ক ও পোর্টাল শেয়ারিং সংক্রান্ত হাইপারলিংক নীতি।',
                        'meta_keywords' => 'হাইপারলিংক নীতি, hyperlink policy sntcssc',
                        'content' => '<h2>হাইপারলিংক নীতি (Hyperlink Policy)</h2>
<p>শিক্ষার্থীদের সুবিধার্থে এই পোর্টালে বিভিন্ন সরকারি ও শিক্ষামূলক ওয়েবসাইটের লিঙ্ক দেওয়া হয়েছে।</p>
<p>যেকোনো শিক্ষাপ্রতিষ্ঠান আমাদের পোর্টালের লিংক স্বাধীনভাবে তাদের সাইটে শেয়ার করতে পারেন।</p>',
                    ],
                ],
            ],
        ];
    }
}
