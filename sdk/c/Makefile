# 统一身份认证中心 · C SDK Makefile
# 依赖：libcurl, cJSON

CC       ?= gcc
CFLAGS   ?= -Wall -Wextra -O2 -std=c99
LDFLAGS  ?= -lcurl -lcjson
INCLUDES ?= -I.

SRC      = uic_sdk.c
OBJ      = $(SRC:.c=.o)
TARGET   = libuicsdk.a
DEMO     = demo

.PHONY: all clean demo

all: $(TARGET)

$(TARGET): $(OBJ)
	ar rcs $@ $^

%.o: %.c uic_sdk.h
	$(CC) $(CFLAGS) $(INCLUDES) -c $< -o $@

demo: $(TARGET) demo.c
	$(CC) $(CFLAGS) $(INCLUDES) demo.c -L. -luicsdk $(LDFLAGS) -o $(DEMO)

clean:
	rm -f $(OBJ) $(TARGET) $(DEMO)

install:
	@echo "请根据目标平台将 libuicsdk.a 与 uic_sdk.h 复制到系统 include/lib 目录"
