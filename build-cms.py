#!/usr/bin/env python3
"""Build the static Q17.tech HTML pages from Decap CMS content files."""
from pathlib import Path
from bs4 import BeautifulSoup
import yaml, json, re, html, sys
ROOT=Path(__file__).resolve().parent

def read_post(path):
    raw=path.read_text(encoding='utf-8')
    if not raw.startswith('---'): raise ValueError(f'No front matter: {path}')
    _, fm, body=raw.split('---',2)
    d=yaml.safe_load(fm) or {}
    # Existing migrated articles store their HTML body in the front-matter `body` field.
    # New CMS-created articles normally store Markdown after the front matter.
    d['_body']=(body.strip() or str(d.get('body') or '').strip())
    d.setdefault('slug',path.stem)
    d.setdefault('title','Без названия')
    d.setdefault('category','Автоматизация')
    d.setdefault('excerpt','')
    d.setdefault('description',d['excerpt'])
    d.setdefault('date','2026-10-01')
    d.setdefault('read_time',5)
    return d

def set_meta(soup, selector, attr, value):
    el=soup.select_one(selector)
    if el: el[attr]=str(value)

def update_article(post):
    slug=post['slug']; target=ROOT/(slug+'.html')
    if target.exists(): soup=BeautifulSoup(target.read_text(encoding='utf-8'),'html.parser')
    else:
        base=ROOT/'article-template.html'
        soup=BeautifulSoup(base.read_text(encoding='utf-8'),'html.parser')
        # Replace template placeholder page with a reusable article shell.
        for m in soup.select('meta[name="robots"]'): m.decompose()
        if soup.title: soup.title.string=f"{post['title']} | Q17.tech"
        h=soup.select_one('h1.page-h1') or soup.select_one('h1')
        if h: h.string=post['title']
        lead=soup.select_one('p.lead')
        if lead: lead.string=post['excerpt']
        badge=soup.select_one('section .badge')
        if badge: badge.string=post['category']
        toc=soup.select_one('.article-toc')
        if toc: toc.decompose()
        author=soup.select_one('.article-author')
        if author: author.decompose()
        # remove related article placeholder links; add back link remains
        for a in soup.select('a[href="[URL].html"], a[href="[УСЛУГА].html"]'):
            parent=a.find_parent(class_='col-md-6')
            if parent: parent.decompose()
    if soup.title: soup.title.string=f"{post['title']} | Q17.tech"
    set_meta(soup,'meta[name="description"]','content',post.get('description') or post['excerpt'])
    set_meta(soup,'meta[property="og:title"]','content',f"{post['title']} | Q17.tech")
    set_meta(soup,'meta[property="og:description"]','content',post.get('description') or post['excerpt'])
    h=soup.select_one('h1.page-h1') or soup.select_one('h1')
    if h: h.clear(); h.append(post['title'])
    lead=soup.select_one('p.lead')
    if lead: lead.clear(); lead.append(post['excerpt'])
    badge=soup.select_one('section .badge')
    if badge: badge.clear(); badge.append(post['category'])
    # Update the last breadcrumb segment when the source is the blank article template.
    crumbs=soup.select('nav[aria-label=\"Хлебные крошки\"] span.text-dark')
    if crumbs: crumbs[-1].clear(); crumbs[-1].append(post['category'])
    # Replace generic CTA copy on newly generated pages; existing custom CTA text is retained.
    for cta in soup.select('.card.bg-dark .card-body'):
        ch=cta.find(['h2','h3','h4'])
        cp=cta.find('p')
        if ch and '[' in ch.get_text(): ch.clear(); ch.append('Разберём вашу задачу бесплатно')
        if cp and '[' in cp.get_text(): cp.clear(); cp.append('Покажем, где теряются заявки, и предложим понятный план действий.')
    # date / read time in the header
    section=next((x for x in soup.select('section') if (x.select_one('h1.page-h1') or x.select_one('h1'))),None)
    if section:
        time=section.select_one('time')
        if time:
            time['datetime']=str(post['date'])[:10]
            time.clear(); time.append(str(post['date'])[:10])
        meta_p=section.select_one('p.text-muted')
        if meta_p:
            meta_p.clear(); meta_p.append(f"{str(post['date'])[:10]} · {post['read_time']} мин чтения")
    body=soup.select_one('.article-body')
    if body:
        # Preserve existing TOC if content contains one; CMS body is trusted site-editor HTML/Markdown.
        body.clear()
        raw=post['_body']
        try:
            import markdown
            raw=markdown.markdown(raw,extensions=['extra','sane_lists'])
        except ImportError:
            pass
        fragment=BeautifulSoup(raw,'html.parser')
        for child in list(fragment.contents): body.append(child)
    # Replace template title placeholders in new article only and ensure public indexability.
    for m in soup.select('meta[name="robots"]'):
        if 'noindex' in m.get('content',''): m.decompose()
    target.write_text(str(soup),encoding='utf-8')

def build_blog(posts):
    p=ROOT/'blog.html'; soup=BeautifulSoup(p.read_text(encoding='utf-8'),'html.parser')
    grid=soup.select_one('#blogGrid')
    if not grid: return
    cta=grid.select_one('.blog-cta')
    # Preserve CTA; replace all cards with CMS content.
    for card in grid.select('.blog-card'): card.decompose()
    icons={'amoCRM':'assets/images/icons/icon-funnel.svg','Телефония':'assets/images/icons/icon-phone.svg','Чат-боты':'assets/images/icons/icon-bot.svg','Автоматизация':'assets/images/icons/icon-trend.svg','Сопровождение':'assets/images/icons/icon-chart.jpg'}
    gradients={'amoCRM':'linear-gradient(135deg,#e8f0fe,#fff)','Телефония':'linear-gradient(135deg,#e0f7fa,#fff)','Чат-боты':'linear-gradient(135deg,#fff3e0,#fff)','Автоматизация':'linear-gradient(135deg,#e8f5e9,#fff)','Сопровождение':'linear-gradient(135deg,#fce4ec,#fff)'}
    for post in sorted(posts,key=lambda x:str(x.get('date','')),reverse=True):
        card=soup.new_tag('a',attrs={'class':'blog-card','data-cl':post['category'],'href':post['slug']+'.html'})
        cover=soup.new_tag('div',attrs={'class':'blog-cover','style':gradients.get(post['category'],gradients['Автоматизация'])})
        img=soup.new_tag('img',src=post.get('image') or icons.get(post['category'],icons['Автоматизация']),alt='',width='44',height='44',loading='lazy'); cover.append(img); card.append(cover)
        content=soup.new_tag('div',attrs={'class':'blog-body'})
        tag=soup.new_tag('span',attrs={'class':'blog-tag'}); tag.string=f"{post['category']} · {post.get('read_time',5)} мин"; content.append(tag)
        h=soup.new_tag('h3'); h.string=post['title']; content.append(h)
        desc=soup.new_tag('p'); desc.string=post.get('excerpt',''); content.append(desc)
        card.append(content)
        if cta: cta.insert_before(card)
        else: grid.append(card)
    p.write_text(str(soup),encoding='utf-8')

def build_pricing():
    data=json.loads((ROOT/'content/settings/pricing.json').read_text(encoding='utf-8'))
    p=ROOT/'index.html'; soup=BeautifulSoup(p.read_text(encoding='utf-8'),'html.parser')
    sec=soup.select_one('#pricing')
    if not sec: return
    h=sec.select_one('h2')
    if h:
        h.clear(); h.append(data.get('title','Тарифы под задачу вашего бизнеса'))
    cols=sec.select('.row > .col-lg-4')
    for i,plan in enumerate(data.get('plans',[])):
        if i>=len(cols): break
        col=cols[i]; badge=col.select_one('.badge'); desc=col.select_one('.card-body p'); price=col.select_one('.card-body h2'); btn=col.select_one('a[href*="contact.html?tariff="]')
        if badge: badge.clear(); badge.append(plan.get('highlight') or plan.get('name','Тариф'))
        if desc: desc.clear(); desc.append(plan.get('description',''))
        if price: price.clear(); price.append(plan.get('price','По запросу'))
        if btn: btn['href']='contact.html?tariff='+html.escape(plan.get('tariff') or plan.get('name','Тариф'),quote=True)
        ul=col.select_one('.card-body ul')
        if ul:
            ul.clear()
            for feature in plan.get('features',[]):
                li=soup.new_tag('li',attrs={'class':'hstack gap-3'})
                icon=soup.new_tag('iconify-icon',attrs={'class':'fs-6 text-dark','icon':'lucide:check'})
                par=soup.new_tag('p',attrs={'class':'mb-0 text-dark'}); par.string=str(feature)
                li.append(icon); li.append(par); ul.append(li)
    p.write_text(str(soup),encoding='utf-8')

def build_site_texts():
    data=json.loads((ROOT/'content/settings/site.json').read_text(encoding='utf-8'))
    for filename in ['index.html','blog.html']:
        p=ROOT/filename; soup=BeautifulSoup(p.read_text(encoding='utf-8'),'html.parser')
        for el in soup.select('[data-cms-field]'):
            key=el.get('data-cms-field')
            if data.get(key): el.clear(); el.append(data[key])
        if filename=='blog.html':
            h=soup.select_one('h1.page-h1') or soup.select_one('h1')
            if h and data.get('blog_title'):
                h.clear(); h.append(data['blog_title'])
            lead=soup.select_one('h1.page-h1 + p')
            if lead and data.get('blog_description'):
                lead.clear(); lead.append(data['blog_description'])
        p.write_text(str(soup),encoding='utf-8')

def main():
    posts=[read_post(p) for p in sorted((ROOT/'content/blog').glob('*.md'))]
    slugs=set()
    for post in posts:
        if not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*',str(post['slug'])): raise ValueError('Slug must use lowercase Latin letters and hyphens: '+str(post['slug']))
        if post['slug'] in slugs: raise ValueError('Duplicate slug: '+post['slug'])
        slugs.add(post['slug'])
        update_article(post)
    build_blog(posts); build_pricing(); build_site_texts()
    print(f'OK: generated {len(posts)} article pages, blog listing, pricing and site text.')
if __name__=='__main__': main()
