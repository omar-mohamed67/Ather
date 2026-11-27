import { noorLessons } from "./noor-albayan.js";
import { readingPages } from "./noor-albayan-transcription.js";

const shortVowels = ["َ", "ِ", "ُ"];
const tanweenMarks = ["ً", "ٍ", "ٌ"];
const tanweenEndings = ["ًا", "ٍ", "ٌ"];
const vowelNames = ["الفتحة", "الكسرة", "الضمة"];
const tanweenNames = ["الفتح", "الكسر", "الضم"];
const shadda = "ّ";
const sukun = "ْ";
const lessonTitles = new Map(
  noorLessons.map((lesson) => [lesson.first, lesson.title]),
);

function bookWords(pages) {
  return [
    ...new Set(
      pages.flatMap((page) =>
        readingPages[page].split("|").map((text) => text.trim()),
      ),
    ),
  ].filter((text) => text && !/\s/u.test(text));
}

function letters(word) {
  return word.match(/\P{M}\p{M}*/gu);
}

function readingRound(prompt, example, choices, answer, feedback) {
  return { prompt, example, choices, answer, feedback };
}

const sukunRounds = bookWords([41, 42]).flatMap((word) => {
  const choices = [...new Set(letters(word))];
  const silentLetters = choices.filter((letter) => letter.includes(sukun));
  if (choices.length < 3 || silentLetters.length !== 1) return [];
  const [answer] = silentLetters;
  return [
    readingRound(
      "أين الحرف الساكن في الكلمة؟",
      word,
      choices,
      answer,
      `الحرف «${answer}» عليه سكون.`,
    ),
  ];
});

function tanweenRounds(pages, markIndex) {
  const ending = tanweenEndings[markIndex];
  return bookWords(pages).flatMap((word) => {
    if (!word.endsWith(ending)) return [];
    const stem = word.slice(0, -ending.length);
    if (!/[بتثجحخدذرزسشصضطظعغفقكلمنهي]$/u.test(stem)) return [];
    return [
      readingRound(
        `أكمل الكلمة بتنوين ${tanweenNames[markIndex]}.`,
        stem,
        tanweenEndings.map((suffix) => stem + suffix),
        word,
        `آخر «${word}» عليه تنوين ${tanweenNames[markIndex]}.`,
      ),
    ];
  });
}

function shaddaRounds(pages, marks, targetMark) {
  const examples = new Map();
  for (const word of bookWords(pages)) {
    const syllables = letters(word).filter((letter) => letter.includes(shadda));
    if (syllables.length !== 1) continue;
    const [syllable] = syllables;
    const [letter] = syllable;
    const mark = marks.find((candidate) => syllable.includes(candidate));
    if (!mark || (targetMark && mark !== targetMark)) continue;

    const answer = letter + shadda + mark;
    const index = marks.indexOf(mark);
    const ending =
      marks === tanweenMarks
        ? `تنوين ${tanweenNames[index]}`
        : vowelNames[index];
    if (examples.has(answer)) continue;
    examples.set(
      answer,
      readingRound(
        "اضغط على الحرف الذي ينتج عند جمع الحرفين.",
        `${letter}${sukun} + ${letter}${mark}`,
        [...marks.map((variant) => letter + shadda + variant), letter + mark],
        answer,
        `نكتب الحرف مرة واحدة مع الشدة و${ending}، كما في «${word}».`,
      ),
    );
  }
  return [...examples.values()];
}

function ruleRounds(prompt, examples) {
  const choices = [...new Set(examples.map(([, answer]) => answer))];
  return examples.map(([example, answer, feedback]) => ({
    ...readingRound(prompt, example, choices, answer, feedback),
    ruleChoices: true,
  }));
}

const noonRounds = ruleRounds("ما حكم النون الساكنة أو التنوين في المثال؟", [
  ["مَنْ ءَامَنَ", "الإظهار", "جاءت الهمزة بعد النون الساكنة."],
  ["يَوْمٌ عَصِيبٌ", "الإظهار", "جاء حرف العين بعد التنوين."],
  ["مَن نَّشَآءُ", "الإدغام بغنة", "جاءت النون بعد النون الساكنة."],
  ["وَمَن لَّمْ", "الإدغام بغير غنة", "جاءت اللام بعد النون الساكنة."],
  ["مِنۢ بَعْدِ", "الإقلاب", "جاءت الباء بعد النون الساكنة."],
  ["وَمَن تَطَوَّعَ", "الإخفاء", "جاءت التاء بعد النون الساكنة."],
]);

const meemRounds = ruleRounds("ما حكم الميم الساكنة في المثال؟", [
  ["هُم بِهِۦ", "الإخفاء الشفوي", "جاءت الباء بعد الميم الساكنة."],
  ["لَكُم بَرَآءَةٌ", "الإخفاء الشفوي", "جاءت الباء بعد الميم الساكنة."],
  [
    "وَهُم مُّهْتَدُونَ",
    "الإدغام الشفوي",
    "جاءت ميم متحركة بعد الميم الساكنة.",
  ],
  ["لَهُم مَّغْفِرَةٌ", "الإدغام الشفوي", "جاءت ميم متحركة بعد الميم الساكنة."],
  ["هُمْ فِيهَا", "الإظهار الشفوي", "جاءت الفاء بعد الميم الساكنة."],
  ["هُمْ وَقُودُ", "الإظهار الشفوي", "جاءت الواو بعد الميم الساكنة."],
]);

const mergingRounds = ruleRounds("ما نوع الإدغام بين الحرفين؟", [
  ["لَكُم مَّوْعِدًا", "المتماثلان", "اجتمعت ميم ساكنة مع ميم متحركة."],
  ["بَل رَّفَعَهُ", "المتقاربان", "جاءت الراء بعد اللام الساكنة."],
  ["قَد تَّبَيَّنَ", "المتجانسان", "جاءت التاء بعد الدال الساكنة."],
]);

const reviewRounds = [
  ...mergingRounds,
  readingRound(
    "أيّ حرف ساكن يُقلقل في هذه الكلمة؟",
    "ٱقْتَرَبَ",
    ["قْ", "تَ", "رَ", "بَ"],
    "قْ",
    "القاف الساكنة من حروف القلقلة.",
  ),
  {
    ...readingRound(
      "كيف نقرأ الراء في هذه الكلمة؟",
      "رِزْقًا",
      ["مرققة", "مفخمة"],
      "مرققة",
      "الراء مكسورة، فنقرأها مرققة.",
    ),
    ruleChoices: true,
  },
  {
    ...readingRound(
      "متى تُنطق الألف التي فوقها صفر مستطيل في هذه الكلمة؟",
      "أَنَا۠",
      ["عند الوقف عليها", "عند وصلها بما بعدها", "في الوقف والوصل"],
      "عند الوقف عليها",
      "تُثبت الألف عند الوقف وتسقط في الوصل.",
    ),
    ruleChoices: true,
  },
  readingRound(
    "بأيّ حركة نبدأ همزة الوصل هنا؟",
    "ٱدْعُ",
    ["أَ", "إِ", "أُ"],
    "أُ",
    "نبدأ بالضمة لأن ثالث الفعل مضموم ضمًّا أصليًّا.",
  ),
  {
    ...readingRound(
      "ماذا يحدث لمدّ الياء عند وصل الكلمتين؟",
      "فِي ٱلنَّهَارِ",
      ["تسقط ياء المد في النطق", "تبقى ياء المد في النطق"],
      "تسقط ياء المد في النطق",
      "يسقط حرف المد في الوصل إذا لقي ساكنًا.",
    ),
    ruleChoices: true,
  },
];

// Each new card corresponds to a circled lesson with no active game.
const lessons = [
  {
    id: "noor-sukun",
    first: 40,
    description: "اعثر على الحرف الساكن في كل كلمة.",
    emoji: "🔎",
    art: "noorSukun",
    bg: "#e7f1ec",
    ink: "#6f9d86",
    rounds: sukunRounds,
  },
  {
    id: "noor-tanween-fath",
    first: 51,
    description: "أكمل آخر الكلمة بالفتحتين.",
    emoji: "✏️",
    art: "noorTanween",
    bg: "#f9eddc",
    ink: "#bb8e54",
    rounds: tanweenRounds([51, 52], 0),
  },
  {
    id: "noor-tanween-kasr",
    first: 54,
    description: "اختر الكلمة التي تنتهي بكسرتين.",
    emoji: "✏️",
    art: "noorTanween",
    bg: "#e4edf8",
    ink: "#789cbb",
    rounds: tanweenRounds([54, 55], 1),
  },
  {
    id: "noor-tanween-damm",
    first: 57,
    description: "أكمل آخر الكلمة بالضمتين.",
    emoji: "✏️",
    art: "noorTanween",
    bg: "#deefe9",
    ink: "#6da58f",
    rounds: tanweenRounds([57, 58], 2),
  },
  {
    id: "noor-shadda-fath",
    first: 63,
    description: "اجمع حرفًا ساكنًا وحرفًا مفتوحًا في حرف مشدّد.",
    emoji: "🧩",
    art: "noorShadda",
    bg: "#f7e8df",
    ink: "#b9856d",
    rounds: shaddaRounds([63, 64], shortVowels, shortVowels[0]),
  },
  {
    id: "noor-shadda-kasr",
    first: 67,
    description: "اجمع حرفًا ساكنًا وحرفًا مكسورًا في حرف مشدّد.",
    emoji: "🧩",
    art: "noorShadda",
    bg: "#e4edf8",
    ink: "#789cbb",
    rounds: shaddaRounds([67, 68], shortVowels, shortVowels[1]),
  },
  {
    id: "noor-shadda-damm",
    first: 71,
    description: "اجمع حرفًا ساكنًا وحرفًا مضمومًا في حرف مشدّد.",
    emoji: "🧩",
    art: "noorShadda",
    bg: "#eaf0dc",
    ink: "#8ca16d",
    rounds: shaddaRounds([71, 72], shortVowels, shortVowels[2]),
  },
  {
    id: "noor-shadda-tanween",
    first: 76,
    description: "اجمع الشدّة والتنوين، واختر الشكل الصحيح.",
    emoji: "🧩",
    art: "noorShaddaTanween",
    bg: "#f6eadc",
    ink: "#aa8f69",
    rounds: shaddaRounds([76], tanweenMarks),
  },
  {
    id: "noor-noon-rules",
    first: 81,
    description: "اقرأ المثال واختر حكم النون الساكنة أو التنوين.",
    emoji: "📖",
    art: "book",
    bg: "#e4eee5",
    ink: "#7c9d7d",
    rounds: noonRounds,
  },
  {
    id: "noor-meem-rules",
    first: 84,
    description: "ميّز أحكام الميم الساكنة من الحرف الذي يليها.",
    emoji: "📖",
    art: "book",
    bg: "#deefe9",
    ink: "#6da58f",
    rounds: meemRounds,
  },
  {
    id: "noor-tajweed-review",
    first: 86,
    description: "راجع الإدغام والقلقلة والراء وأحكام الوصل والوقف.",
    emoji: "🎯",
    art: "book",
    bg: "#f8eedb",
    ink: "#a8895e",
    rounds: reviewRounds,
  },
];

export const noorGameCards = lessons.map(({ first, rounds, ...card }) => ({
  ...card,
  title: lessonTitles.get(first),
  category: first >= 81 ? "quran" : "arabic",
  badge: "نور البيان",
}));

export const noorGameRounds = new Map(
  lessons.map(({ id, rounds }) => [id, rounds]),
);

const gameLessons = [
  ["letter-hunt", [[3]]],
  ["vowel-match", [[4, "fatha"], [14, "kasra"], [19, "damma"]]],
  ["long-vowels", [[25, "fatha"], [29, "kasra"], [33, "damma"]]],
  ...lessons.map(({ id, first }) => [id, [[first]]]),
];

export const noorLessonGames = new Map(
  gameLessons.flatMap(([id, pages]) =>
    pages.map(([page, focus]) => [page, { id, focus, title: lessonTitles.get(page) }]),
  ),
);

export const noorGameTopics = new Map(
  gameLessons.map(([id, pages]) => [id, pages.map(([page]) => lessonTitles.get(page))]),
);
