"""Offline checks for the private World Literature source, never a database fixture."""
import json, math, re, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class WorldLiteratureCourse(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.c=json.loads((ROOT/'private/world-literature-course.json').read_text())
 def test_identity_and_existing_course_contract(self):
  c=self.c
  self.assertEqual((c['id'],c['code'],c['durationWeeks'],c['capacity']),('world-literature','LIT 201',12,6))
  self.assertEqual(c['prerequisite'],'College Success')
  required=set(json.loads((ROOT/'private/term-courses.json').read_text())['philosophy'])
  self.assertTrue(required<=set(c))
  self.assertEqual([m['id'] for m in c['weeks']],list(range(10)))
 def test_substantial_original_guides_and_specific_assignment_prompts(self):
  for m in self.c['weeks']:
   with self.subTest(module=m['id']+1):
    words=len(' '.join(s['text'] for s in m['sections']).split())
    self.assertGreaterEqual(words,650)
    self.assertEqual(m['originalReadingMinutes'],math.ceil(words/200))
    self.assertGreaterEqual(len(m['outcomes']),3)
    self.assertIn('at least 300 words',m['opening'])
    self.assertIn('no more than 500 words',m['closing'])
    for prompt in (m['opening'],m['closing']):
     for word in ('introduction','body','conclusion','APA','reference'):
      self.assertIn(word,prompt)
    self.assertGreater(len(m['case']),80)
    self.assertGreater(len(m['practice']),100)
 def test_all_source_references_resolve_and_have_reading_targets(self):
  sources=self.c['sources']; used=set()
  def visit(x):
   if isinstance(x,dict):
    for key,value in x.items():
     if key=='sources' and isinstance(value,list):
      self.assertTrue(value)
      for source_id in value:
       self.assertIn(source_id,sources);used.add(source_id)
     elif key!='sources': visit(value)
   elif isinstance(x,list):
    for value in x: visit(value)
  for m in self.c['weeks']:
   self.assertTrue(m['readings'])
   for source_id in m['readings']:
    self.assertIn(source_id,sources);used.add(source_id)
   visit(m)
  visit(self.c['final'])
  self.assertEqual(set(sources),used)
  for source_id,s in sources.items():
   with self.subTest(source=source_id):
    for key in ('title','author','year','url','assignment','publisher','license','checked'):
     self.assertTrue(s.get(key))
    self.assertTrue(s['url'].startswith('https://'))
    self.assertRegex(s['assignment'],r'(poem|chapter|section|pages|pp\.|transcript|publication page|complete titled|three acts|full post)')
    self.assertRegex(s['assignment'],r'\d+–\d+ minutes')
  self.assertIn('Optional',sources['translation-research']['assignment'])
  self.assertIn('not needed',sources['translation-research']['assignment'])
 def test_exact_assessment_shapes_boolean_types_and_unique_questions(self):
  allq=[]
  for m in self.c['weeks']:
   self.assertEqual(len(m['questions']),10)
   self.assertEqual(set(q['type'] for q in m['questions']),{'tf'})
   self.assertEqual(set(q['correct'] for q in m['questions']),{True,False})
   for q in m['questions']:
    self.assertIs(type(q['correct']),bool)
   allq+=m['questions']
  final=self.c['final']
  self.assertEqual((len(final['a']),len(final['b'])),(15,10))
  for q in final['a']:
   self.assertEqual(q['type'],'mcq');self.assertEqual(len(q['answers']),4)
   self.assertEqual(len(set(q['answers'])),4)
   self.assertIs(type(q['correct']),int);self.assertIn(q['correct'],range(4))
  for q in final['b']:
   self.assertEqual(q['type'],'statements');self.assertEqual(len(q['statements']),4)
   self.assertEqual(set(s['correct'] for s in q['statements']),{True,False})
   for s in q['statements']:
    self.assertIs(type(s['correct']),bool)
    self.assertGreater(len(s['explanation']),45)
  allq+=final['a']+final['b']
  self.assertEqual(len({q['id'] for q in allq}),125)
  self.assertEqual(len({q['question'] for q in allq}),125)
  for q in allq:
   if q['type']!='statements': self.assertGreater(len(q['explanation']),45)
  # Existing framework creates its midterm from two real questions in each teaching module.
  self.assertEqual(len([q for m in self.c['weeks'] for q in m['questions'][:2]]),20)
 def test_accessible_media_and_no_optional_research_quiz_dependency(self):
  videos=[m['video'] for m in self.c['weeks'] if m['video']]
  self.assertEqual(len(videos),1)
  v=videos[0]
  self.assertEqual((v['id'],v['start'],v['end']),('D9Ihs241zeg',0,1112))
  self.assertIn('transcript',v['assignment'])
  for m in self.c['weeks']:
   for q in m['questions']: self.assertNotIn('translation-research',q['sources'])
  for q in self.c['final']['a']+self.c['final']['b']:
   self.assertNotIn('translation-research',q['sources'])
if __name__=='__main__': unittest.main()
