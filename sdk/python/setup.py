# -*- coding: utf-8 -*-
from setuptools import setup, find_packages

setup(
    name="uic-sdk",
    version="1.0.0",
    description="统一身份认证中心(UCenter) Python SDK，支持同步与异步调用",
    long_description=open("README.md", encoding="utf-8").read(),
    long_description_content_type="text/markdown",
    packages=find_packages(exclude=["tests", "tests.*"]),
    include_package_data=True,
    python_requires=">=3.8",
    install_requires=[
        "requests>=2.25",
    ],
    extras_require={
        "async": ["aiohttp>=3.8"],
        "dev": ["pytest", "pytest-asyncio"],
    },
    classifiers=[
        "Programming Language :: Python :: 3",
        "License :: OSI Approved :: MIT License",
        "Operating System :: OS Independent",
    ],
    keywords="uic sso sdk ucenter auth",
    project_urls={
        "Homepage": "http://127.0.0.5",
    },
)